//! The fight itself: a port of `BattleEngineSteemNova` (per-ship simulation).
//!
//! Rules, exactly as the PHP engine plays them (and in the same order of random draws):
//! * up to `rounds` fights; in each, every ship of every attacking fleet fires once, then every ship of every defending
//!   fleet (ships destroyed earlier in the same fight still fire; destruction is settled after both sides fired);
//! * a shot picks a random enemy ship: one draw over all ships of the enemy side (to find the fleet), one draw over the
//!   ships of that fleet;
//! * a shot with an attack below 1 % of the target's current shield bounces off; otherwise the shield absorbs first,
//!   the rest hits the armor;
//! * a ship with `explode > 0` (ships 2xx) hit while below `explode` x its initial armor explodes when a draw in
//!   `0..=initial armor` exceeds its armor; a ship with armor <= 0 is destroyed;
//! * rapid fire: if the victim's unit id is in the shooter's table with value N, the shooter fires again with
//!   probability (N-1)/N (one draw `rand(1, N)`, even for a bounced shot);
//! * after the fight destroyed ships are removed and all shields restored.
//!
//! On top of the PHP engine's behaviour the engine records the per-round statistics the combat report shows
//! (damage, shield hits, bounces, kills, rapid fire, explosions per fleet/unit pair); recording them draws no
//! random numbers.

use std::collections::HashMap;
use std::hash::{BuildHasherDefault, Hasher};

use crate::input::{BattleInput, FleetInput};
use crate::rng::{MtRand, Range32};

/// Default memory guard: ships per battle (16 bytes each, about 1 GB at the default) before the engine refuses.
pub const DEFAULT_MAX_SHIPS: u64 = 60_000_000;

/// Statistic indices inside a [`Cell`]; the order of [`STAT_NAMES`] is the order of the report JSON.
pub const DAMAGE_DONE: usize = 0;
pub const DAMAGE_DEAD: usize = 1;
pub const SHIELD_HIT: usize = 2;
pub const SHIP_DESTROYED: usize = 3;
pub const SHIELD_BOUNCED: usize = 4;
pub const STUNNED_DONE: usize = 5;
pub const RAPID_FIRE_DONE: usize = 6;
pub const EXPLOSION_TRIGGERED: usize = 7;
pub const EXPLOSION_DAMAGE_DONE: usize = 8;
pub const RF_STOPPED: usize = 9;
/// Number of statistics.
pub const STATS: usize = 10;
/// JSON names of the statistics.
pub const STAT_NAMES: [&str; STATS] = [
    "damage_done",
    "damage_dead",
    "shield_hit",
    "ship_destroyed",
    "shield_bounced",
    "stunned_done",
    "rapid_fire_done",
    "explosion_triggered",
    "explosion_damage_done",
    "rf_stopped",
];

/// All statistics of one (shooting unit slot, target unit slot) pair.
pub type Cell = [f64; STATS];

/// One ship: 16 bytes. `armor` is the current armor; `shield` is the current shield with the sign bit used as the PHP
/// engine's `explode` flag (set when the ship is destroyed; it stays in the list until the fight is over). The unit
/// type is not stored: the ships of a fleet are contiguous per type, see [`Fleet::bounds`].
#[derive(Clone, Copy)]
struct Ship {
    shield: f64,
    armor: f64,
}

const SIGN: u64 = 1 << 63;

impl Ship {
    #[inline(always)]
    fn dead(&self) -> bool {
        self.shield.to_bits() & SIGN != 0
    }

    #[inline(always)]
    fn shield(&self) -> f64 {
        self.shield.abs()
    }

    /// Sets the shield keeping the dead flag.
    #[inline(always)]
    fn set_shield(&mut self, value: f64) {
        self.shield = f64::from_bits(value.to_bits() | (self.shield.to_bits() & SIGN));
    }

    #[inline(always)]
    fn kill(&mut self) {
        self.shield = f64::from_bits(self.shield.to_bits() | SIGN);
    }
}

/// A unit type of a fleet with its (tech-adjusted) values.
pub struct UnitType {
    /// Unit id.
    pub id: u32,
    attack: f64,
    shield: f64,
    hull: f64,
    explode: f64,
    rapid: Vec<(u32, i64)>,
    /// the explosion roll `rand(0, (int) hull)` (None when the hull does not fit 32 bits)
    explosion_roll: Option<Range32>,
}

/// A fleet: unit types, the ships still alive (plus those destroyed in the running fight).
pub struct Fleet {
    /// Fleet id as a JSON key.
    pub key: String,
    /// Unit types in input order.
    pub types: Vec<UnitType>,
    /// Ships ordered by unit type (the order of `types`).
    ships: Vec<Ship>,
    /// `bounds[t]` = index after the last ship of type `t`: type `t` owns `bounds[t-1]..bounds[t]`.
    bounds: Vec<u32>,
    /// First "slot" of this fleet: slot = slot_base + type index, unique per side.
    slot_base: usize,
    /// victim pick inside this fleet during a fight: `rand(0, ships - 1)`
    pick: Range32,
}

/// One side of the battle.
pub struct Side {
    /// Fleets in firing order.
    pub fleets: Vec<Fleet>,
    /// Total number of (fleet, type) slots.
    pub slots: usize,
}

impl Side {
    fn build(inputs: &[FleetInput]) -> Side {
        let mut fleets = Vec::with_capacity(inputs.len());
        let mut slot_base = 0;
        for input in inputs {
            let mut types = Vec::with_capacity(input.ships.len());
            let mut ships = Vec::new();
            let mut bounds = Vec::with_capacity(input.ships.len());
            for ship in &input.ships {
                types.push(UnitType {
                    id: ship.shipid,
                    attack: ship.attack,
                    shield: ship.shield,
                    hull: ship.hull,
                    explode: ship.explode,
                    rapid: ship.rapid_fire_table(),
                    explosion_roll: u32::try_from(ship.hull as i64).ok().map(Range32::new),
                });
                ships.resize(ships.len() + ship.amount as usize, Ship { shield: ship.shield, armor: ship.hull });
                bounds.push(ships.len() as u32);
            }
            let count = types.len();
            fleets.push(Fleet { key: input.key(), types, ships, bounds, slot_base, pick: Range32::new(0) });
            slot_base += count;
        }
        Side { fleets, slots: slot_base }
    }

    /// Ships alive (or destroyed in the running fight and not yet removed).
    pub fn ship_count(&self) -> u64 {
        self.fleets.iter().map(|f| f.ships.len() as u64).sum()
    }

    /// (fleet index, type index) of a slot.
    pub fn locate(&self, slot: usize) -> (usize, usize) {
        let fleet = self.fleets.iter().rposition(|f| f.slot_base <= slot && !f.types.is_empty()).unwrap_or(0);
        (fleet, slot - self.fleets[fleet].slot_base)
    }

    /// Removes destroyed ships (armor <= 0 or flagged), keeping the order, and moves the type bounds.
    fn destroy(&mut self) {
        for fleet in &mut self.fleets {
            let mut write = 0usize;
            let mut read = 0usize;
            for bound in fleet.bounds.iter_mut() {
                let end = *bound as usize;
                while read < end {
                    let ship = fleet.ships[read];
                    if !(ship.armor <= 0.0 || ship.dead()) {
                        fleet.ships[write] = ship;
                        write += 1;
                    }
                    read += 1;
                }
                *bound = write as u32;
            }
            fleet.ships.truncate(write);
        }
    }

    /// Restores every surviving ship's shield (and clears nothing else: survivors are never flagged).
    fn restore_shields(&mut self) {
        for fleet in &mut self.fleets {
            let mut start = 0usize;
            for (ty, &end) in fleet.types.iter().zip(&fleet.bounds) {
                for ship in &mut fleet.ships[start..end as usize] {
                    ship.shield = ty.shield;
                }
                start = end as usize;
            }
        }
    }

    /// Per fleet and unit type: attack, shield, hull sum of the surviving ships and their number.
    fn snapshot(&self) -> Vec<Vec<TypeState>> {
        self.fleets
            .iter()
            .map(|fleet| {
                let mut start = 0usize;
                fleet
                    .types
                    .iter()
                    .zip(&fleet.bounds)
                    .map(|(ty, &end)| {
                        let range = &fleet.ships[start..end as usize];
                        start = end as usize;
                        let amount = range.len() as u64;
                        TypeState {
                            attack: amount as f64 * ty.attack,
                            shield: amount as f64 * ty.shield,
                            hull: range.iter().map(|s| s.armor).sum(),
                            amount,
                            lost: 0,
                        }
                    })
                    .collect()
            })
            .collect()
    }
}

/// State of one unit type of one fleet at the end of a round.
#[derive(Clone, Copy, Default, Debug)]
pub struct TypeState {
    /// Summed attack of the surviving ships.
    pub attack: f64,
    /// Summed (full) shield of the surviving ships.
    pub shield: f64,
    /// Summed current armor of the surviving ships.
    pub hull: f64,
    /// Surviving ships.
    pub amount: u64,
    /// Ships lost in this round.
    pub lost: u64,
}

/// Cheap multiplicative hash for the statistics keys (public only because it appears in a public type).
#[derive(Default)]
pub struct KeyHasher(u64);

impl Hasher for KeyHasher {
    fn finish(&self) -> u64 {
        self.0
    }
    fn write(&mut self, bytes: &[u8]) {
        for byte in bytes {
            self.0 = (self.0 ^ *byte as u64).wrapping_mul(0x100_0000_01b3);
        }
    }
    fn write_u64(&mut self, value: u64) {
        self.0 = (value ^ (value >> 29)).wrapping_mul(0x9e37_79b9_7f4a_7c15);
    }
}

/// Statistics of one side's shots in one fight, keyed by (shooter slot, target slot): a dense table for small
/// battles, a hash map when the slot matrix would be large.
pub enum Store {
    /// shooter slots x target slots cells
    Dense { cells: Vec<Cell>, victim_slots: usize },
    /// Only the pairs that occurred.
    Sparse(HashMap<u64, Cell, BuildHasherDefault<KeyHasher>>),
}

impl Store {
    fn new(shooter_slots: usize, victim_slots: usize) -> Store {
        if shooter_slots * victim_slots <= 1 << 16 {
            Store::Dense { cells: vec![[0.0; STATS]; shooter_slots * victim_slots], victim_slots }
        } else {
            Store::Sparse(HashMap::default())
        }
    }

    #[inline]
    fn cell(&mut self, shooter: usize, victim: usize) -> &mut Cell {
        match self {
            Store::Dense { cells, victim_slots } => &mut cells[shooter * *victim_slots + victim],
            Store::Sparse(map) => map.entry(((shooter as u64) << 32) | victim as u64).or_insert([0.0; STATS]),
        }
    }

    /// All non-empty cells as (shooter slot, target slot, values).
    pub fn entries(&self) -> Vec<(usize, usize, Cell)> {
        match self {
            Store::Dense { cells, victim_slots } => cells
                .iter()
                .enumerate()
                .filter(|(_, c)| c.iter().any(|v| *v != 0.0))
                .map(|(i, c)| (i / victim_slots, i % victim_slots, *c))
                .collect(),
            Store::Sparse(map) => map.iter().map(|(k, c)| ((*k >> 32) as usize, (*k & 0xffff_ffff) as usize, *c)).collect(),
        }
    }
}

/// One fight of the result: state after the fight plus the statistics of that fight (round 0 = start, no statistics).
pub struct RoundResult {
    /// Attacker unit states per fleet and type.
    pub attacker: Vec<Vec<TypeState>>,
    /// Defender unit states per fleet and type.
    pub defender: Vec<Vec<TypeState>>,
    /// Statistics of the attackers' shots.
    pub attacker_stats: Option<Store>,
    /// Statistics of the defenders' shots.
    pub defender_stats: Option<Store>,
}

/// Result of a battle.
pub struct BattleResult {
    /// Attacking fleets (final state: surviving ships).
    pub attacker: Side,
    /// Defending fleets.
    pub defender: Side,
    /// Start state plus one entry per fight.
    pub rounds: Vec<RoundResult>,
    /// 1 attacker won, -1 defender won, 0 draw.
    pub outcome: i32,
    /// 32-bit outputs consumed from the generator.
    pub rng_draws: u64,
    /// The seed that was used (given or taken from the OS).
    pub seed: u32,
}

/// Runs one fight at a time against the enemy side: the shooting loop.
struct Phase<'a> {
    victims: &'a mut Side,
    /// inclusive prefix sums of the victims' ship counts per fleet
    cumulative: Vec<usize>,
    /// prefetch only pays when the victims do not fit the cache (about 1M ships = 16 MB)
    prefetch_on: bool,
    /// fleet pick over all ships of the side: `rand(0, total - 1)`
    pick: Range32,
    rng: &'a mut MtRand,
    store: &'a mut Store,
}

impl Phase<'_> {
    /// Prefetches the ship that the shot starting `ahead` outputs from now would pick, assuming it draws its fleet and
    /// ship straight away (no rejection) and no explosion or rapid-fire roll comes in between. A wrong guess only wastes
    /// a prefetch; nothing is consumed and no result depends on it.
    #[inline(always)]
    fn prefetch(&self, ahead: usize) {
        let (Some(a), Some(b)) = (self.rng.peek(ahead), self.rng.peek(ahead + 1)) else { return };
        let Some(ran) = self.pick.map(a) else { return };
        let ran = ran as usize;
        let Some(fleet_index) = self.cumulative.iter().position(|&c| ran < c) else { return };
        let fleet = &self.victims.fleets[fleet_index];
        let Some(index) = fleet.pick.map(b) else { return };
        if let Some(ship) = fleet.ships.get(index as usize) {
            #[cfg(target_arch = "x86_64")]
            unsafe {
                core::arch::x86_64::_mm_prefetch((ship as *const Ship).cast::<i8>(), core::arch::x86_64::_MM_HINT_T0);
            }
            #[cfg(not(target_arch = "x86_64"))]
            let _ = ship;
        }
    }

    /// One shot of one ship (`shooter`, in slot `slot`), plus its rapid fire re-shots. See the module docs.
    fn shoot(&mut self, shooter: &UnitType, slot: usize) {
        loop {
            let ran = self.rng.draw(&self.pick) as usize;
            let mut fleet_index = 0;
            while ran >= self.cumulative[fleet_index] {
                fleet_index += 1;
            }
            let victim_index = self.rng.draw(&self.victims.fleets[fleet_index].pick) as usize;
            if self.prefetch_on {
                self.prefetch(0);
                self.prefetch(2);
                self.prefetch(4);
                self.prefetch(6);
            }
            let fleet = &mut self.victims.fleets[fleet_index];
            let Fleet { types, ships, bounds, slot_base, .. } = fleet;
            let type_index = bounds.partition_point(|&end| end as usize <= victim_index);
            let victim_type = &types[type_index];
            let victim_slot = *slot_base + type_index;
            let victim = &mut ships[victim_index];
            let cell = self.store.cell(slot, victim_slot);

            let attack = shooter.attack;
            let shield = victim.shield();
            if attack * 100.0 > shield {
                let was_dead = victim.dead();
                let armor_before = victim.armor;
                let penetration = attack - shield;
                let rest;
                if penetration >= 0.0 {
                    cell[SHIELD_HIT] += shield;
                    victim.set_shield(0.0);
                    victim.armor -= penetration;
                    rest = penetration;
                } else {
                    cell[SHIELD_HIT] += attack;
                    victim.set_shield(shield - attack);
                    rest = 0.0;
                }
                if rest > 0.0 {
                    if was_dead {
                        cell[DAMAGE_DEAD] += rest;
                    } else {
                        let done = rest.min(armor_before.max(0.0));
                        cell[DAMAGE_DONE] += done;
                        cell[DAMAGE_DEAD] += rest - done;
                    }
                }

                if victim_type.explode > 0.0 && !was_dead && victim.armor > 0.0 && victim.armor < victim_type.explode * victim_type.hull {
                    let ran = match &victim_type.explosion_roll {
                        Some(range) => self.rng.draw(range) as i64,
                        None => self.rng.rand(0, victim_type.hull as i64),
                    };
                    if ran as f64 > victim.armor {
                        cell[EXPLOSION_TRIGGERED] += 1.0;
                        cell[EXPLOSION_DAMAGE_DONE] += victim.armor;
                        victim.kill();
                    }
                }
                if victim.armor <= 0.0 && !victim.dead() {
                    victim.kill();
                    cell[SHIP_DESTROYED] += 1.0;
                }
            } else {
                cell[SHIELD_BOUNCED] += attack;
            }

            // rapid fire
            if shooter.rapid.is_empty() {
                return;
            }
            let victim_id = victim_type.id;
            let matches = shooter.rapid.iter().filter(|(id, _)| *id == victim_id).count();
            let mut seen = 0;
            let mut again = false;
            for &(id, n) in &shooter.rapid {
                if id != victim_id {
                    continue;
                }
                seen += 1;
                if self.rng.rand(1, n) < n {
                    self.store.cell(slot, victim_slot)[RAPID_FIRE_DONE] += 1.0;
                    if seen == matches {
                        again = true; // the common case (one table entry): the shot repeats as a loop
                    } else {
                        self.shoot(shooter, slot);
                    }
                }
            }
            if !again {
                return;
            }
        }
    }
}

/// All ships of `shooters` fire once at `victims`; returns the statistics of these shots.
fn fire(shooters: &Side, victims: &mut Side, rng: &mut MtRand) -> Store {
    let mut store = Store::new(shooters.slots, victims.slots);
    let mut cumulative = Vec::with_capacity(victims.fleets.len());
    let mut total = 0usize;
    for fleet in &victims.fleets {
        total += fleet.ships.len();
        cumulative.push(total);
    }
    for fleet in &mut victims.fleets {
        fleet.pick = Range32::new(fleet.ships.len().saturating_sub(1) as u32);
    }
    let pick = Range32::new(total.saturating_sub(1) as u32);
    let mut phase = Phase { victims, cumulative, prefetch_on: total > 1 << 20, pick, rng, store: &mut store };
    for fleet in &shooters.fleets {
        let mut start = 0usize;
        for (index, (ty, &end)) in fleet.types.iter().zip(&fleet.bounds).enumerate() {
            for _ in start..end as usize {
                phase.shoot(ty, fleet.slot_base + index);
            }
            start = end as usize;
        }
    }

    store
}

/// Seed from the operating system (`/dev/urandom`), falling back to the clock.
fn os_seed() -> u32 {
    use std::io::Read;
    let mut bytes = [0u8; 4];
    if std::fs::File::open("/dev/urandom").and_then(|mut f| f.read_exact(&mut bytes)).is_ok() {
        return u32::from_le_bytes(bytes);
    }
    let now = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.subsec_nanos() ^ d.as_secs() as u32);

    now.unwrap_or(0x5eed)
}

/// Fights the battle. Errors: more ships than the memory guard allows.
pub fn run(input: &BattleInput) -> Result<BattleResult, String> {
    let ships: u64 = input.attacker.iter().chain(&input.defender).flat_map(|f| &f.ships).map(|s| s.amount).sum();
    // the victim picks are 32-bit draws: never accept more ships than that
    let limit = input.max_ships.unwrap_or(DEFAULT_MAX_SHIPS).min(4_000_000_000);
    if ships > limit {
        return Err(format!("battle too large: {ships} ships (limit {limit})"));
    }

    let seed = input.seed.map(|s| s as u32).unwrap_or_else(os_seed);
    let mut rng = MtRand::new(seed);
    let mut attacker = Side::build(&input.attacker);
    let mut defender = Side::build(&input.defender);

    let mut rounds = vec![RoundResult { attacker: attacker.snapshot(), defender: defender.snapshot(), attacker_stats: None, defender_stats: None }];
    for _ in 0..input.rounds {
        if attacker.ship_count() == 0 || defender.ship_count() == 0 {
            break;
        }
        let attacker_stats = fire(&attacker, &mut defender, &mut rng);
        let defender_stats = fire(&defender, &mut attacker, &mut rng);
        attacker.destroy();
        defender.destroy();
        attacker.restore_shields();
        defender.restore_shields();

        let mut round = RoundResult {
            attacker: attacker.snapshot(),
            defender: defender.snapshot(),
            attacker_stats: Some(attacker_stats),
            defender_stats: Some(defender_stats),
        };
        let previous = rounds.last().expect("start state");
        for (now, before) in [(&mut round.attacker, &previous.attacker), (&mut round.defender, &previous.defender)] {
            for (fleet_now, fleet_before) in now.iter_mut().zip(before) {
                for (state, old) in fleet_now.iter_mut().zip(fleet_before) {
                    state.lost = old.amount - state.amount;
                }
            }
        }
        rounds.push(round);
    }

    let (att, def) = (attacker.ship_count(), defender.ship_count());
    let outcome = if att == 0 && def > 0 {
        -1
    } else if att > 0 && def == 0 {
        1
    } else {
        0
    };

    Ok(BattleResult { attacker, defender, rounds, outcome, rng_draws: rng.draws(), seed })
}

# Battle engine contract (PHP <-> Rust)

How `App\Game\BattleEngine\BattleEngineRust` talks to the Rust library (`tools/battlerust`, shipped as `bin/libbattlerust.so`),
what the PHP side reads back, and how the Rust engine relates to the PHP engine `BattleEngineSteemNova`.

## Process and C ABI

```
PHP (proc_open) -> bin/battlerust (C launcher, tools/battlerust-cli) -> dlopen bin/libbattlerust.so
   stdin: battle JSON            fight_battle_rounds(const char *json)  ->  char *result JSON (or NULL)
   stdout: result JSON           battlerust_free(char *result)          (optional, new library only)
```

| Symbol | Meaning |
| --- | --- |
| `char *fight_battle_rounds(const char *input_json)` | NUL-terminated UTF-8 JSON in, newly allocated NUL-terminated JSON out. NULL on error (message on stderr). |
| `void battlerust_free(char *result)` | Frees a result string. The launcher calls it when the symbol exists. The first library never freed its result (the launcher process ends right after); the launcher still works with it. |
| `const char *battlerust_version(void)` | Static version string, e.g. `1.0.0`. |

Launcher exit codes: 0 ok, 2 library missing/unloadable, 3 symbol missing, 4 input error, 5 engine returned nothing. PHP treats
any non-zero exit or empty output as a failed fight. `BattleEngineRust::calculateAttack()` then runs the PHP engine instead
(and `getName()` reports `steemnova`, so the report is stored in the PHP format).

The old library printed progress lines to stdout ("Round 0: Fleet A size: ..."); the launcher redirects stdout to stderr while
the library runs. The new library prints nothing on success.

## Input JSON

Built by `BattleEngineRust::createRustJson()`:

```json
{
  "rounds": 6,
  "seed": 42,
  "maxShips": 50000000,
  "attacker": [ { "name": 0, "ships": [ { ... } ] } ],
  "defender": [ { "name": 0, "ships": [ { ... } ] } ]
}
```

| Key | Meaning |
| --- | --- |
| `rounds` | Maximum number of fights (PHP `MAX_ATTACK_ROUNDS`, 6). Default 6. |
| `seed` | Optional. `mt_srand()` seed (low 32 bits used). Absent: the library seeds from `/dev/urandom`. Only tests and diagnostics set it (`BattleEngineRust::useSeed()`). |
| `maxShips` | Optional memory guard, default 60 000 000 ships; capped at 4e9. A ship costs 16 bytes (armor and shield as f64, nothing else), so 60 million ships are about 960 MB of state and a fight peaks at about 930 MB RSS (measured with 59.6 million ships), i.e. the default keeps a fight within roughly 1 GB. Larger battles are refused (NULL) and fought by the PHP engine, which scales huge fleets down. |
| `attacker`, `defender` | Fleets in firing order. The defending planet is the fleet with `name` 0, as in the PHP engine. |
| fleet `name` | The PHP fleet id (number or string); the key of the fleet in the result. |

Ship (one entry per unit type of the fleet, **in the order the PHP engine creates its ships**):

| Key | Meaning |
| --- | --- |
| `shipid` | Unit id (202 ... 215 ships, 401 ... 408 defense). |
| `attack` | Attack per shot, `CombatCaps.attack * (1 + 0.1 * weapons tech)`. **Not rounded**: parity needs the exact floats. |
| `hull` | Armor of one ship, `(metal + crystal cost) / 10 * (1 + 0.1 * armor tech)`, not rounded. |
| `shield` | `CombatCaps.shield * (1 + 0.1 * shield tech)`, not rounded. |
| `explode` | Explosion threshold: 0.7 for ships (id 2xx), 0 for defense. |
| `amount` | Number of ships. Types with 0 ships are listed too (they appear in the result with `amount` 0). |
| `rapidfire` | `{ "<victim id>": N }`: shoot again with probability (N-1)/N. PHP encodes an empty table as `{}` or `[]`; both are accepted. |
| `bigshield`, `stun` | Sent for the old library, ignored (the PHP engine has neither mechanic). |

Floats must round-trip exactly: PHP writes the shortest representation (`serialize_precision = -1`), the crate parses with
serde_json's `float_roundtrip`.

## Output JSON

```json
{
  "rounds": [ { "attacker": { "Roundstats": {...}, "Statistics": {...} }, "defender": {...} } ],
  "outcome": 1,
  "rngDraws": 2706,
  "seed": 3190018220,
  "engine": "battlerust 1.0.0"
}
```

`rounds[0]` is the start state, `rounds[i]` the state after fight *i* together with the statistics of fight *i*. A battle
that ends in fight *n* has *n + 1* entries (the PHP engine's `rw` has the same number). `outcome`: `1` attacker won,
`-1` defender won, `0` draw (both sides alive after the last fight, or both destroyed). `rngDraws`: how many 32-bit outputs of the
generator were consumed; `seed`: the seed used (a real battle can be replayed with it). PHP reads `rounds` and `outcome`,
and `rngDraws` when it was given a seed.

### Roundstats

`Roundstats[<fleet name>][<unit id>] = { attack, shield, hull, amount, lost, stunned, escape }` per side and round, for every
unit type of the input (also with `amount` 0), fleets and types in input order:

* `amount`: ships alive after the fight, `lost`: ships lost in this fight (always 0 in `rounds[0]`);
* `attack` = amount x attack, `shield` = amount x full shield, `hull` = summed **current** armor of the survivors;
* `stunned`, `escape`: always 0 (no such mechanics).

`BattleEngineRust::parseRustJson()` takes the losses from `lost`, the remaining units from the PHP fleets minus the losses.

### Statistics

Per side: the shots of that side. Each statistic is `{ "maps": { <from fleet>: { <to fleet>: { <from unit>: { <to unit>: value } } } }, "sum": total }`
(maps are `{}` when empty), in the order `damage_done, damage_dead, shield_hit, ship_destroyed, shield_bounced, stunned_done,
rapid_fire_done, explosion_triggered, explosion_damage_done, rf_stopped`. Only the first eight are shown by the report
(`shared.mission.raport.rust.html.twig`, `public/scripts/game/raport.js`); the report header adds up
`damage_done + damage_dead + shield_hit + shield_bounced` as "firepower" and the last two as "absorbed by shields".

| Statistic | Counts, per shot |
| --- | --- |
| `shield_hit` | attack absorbed by the target's shield |
| `damage_done` | attack that reached the armor of a ship still alive, capped at its remaining armor |
| `damage_dead` | the rest: overkill on the killing shot and everything shot at an already destroyed ship |
| `shield_bounced` | the whole attack of a shot below 1 % of the target's shield (no effect) |
| `ship_destroyed` | +1 when the shot took the armor to 0 or below |
| `explosion_triggered` | +1 when the shot made a ship below 70 % armor explode |
| `explosion_damage_done` | the armor the exploding ship had left |
| `rapid_fire_done` | +1 per extra shot granted by rapid fire |
| `stunned_done`, `rf_stopped` | always empty |

So per side `damage_done + damage_dead + shield_hit + shield_bounced` = attack x (shots + rapid-fire shots) exactly.

## Relation to the PHP engine

The library is a port of `BattleEngineSteemNova::fight/shoot/destroy`: per-ship simulation, the same order of shooters,
the same random draws in the same order (victim fleet, victim ship, explosion roll, rapid-fire roll), simultaneous
destruction after both sides fired, shields restored after each fight.

**Randomness.** PHP's `mt_rand()`/`rand()` is MT19937 with PHP's seeding and range mapping (rejection sampling, one 32-bit
output per attempt; ranges wider than 2^32 take two outputs, the **first** one as the low half; a degenerate range such as
`rand(0, 0)` still consumes a draw). `src/rng.rs` implements this bit for bit (checked against sequences generated by PHP
8.4, `tools/battlerust/tests/rng.rs`). With the same seed the library and the PHP engine (`mt_srand($seed)`) therefore play
**the same fight**.

**What PHP computes after the fight** (identically for both engines, in the same order of random draws): losses and kill
statistics, debris, the defense rebuild (`rand(60, 80)` per unit type above 10 losses, else one `rand(1, 100)` per ship, units in
fleet order), wreck field, per-user lost value and transport capacity. With a seed `BattleEngineRust` moves PHP's generator
to where the PHP engine would stand after the same fight (`mt_srand($seed)` and `rngDraws` calls of `mt_rand()`), so these
rolls match as well. `tests/Integration/BattleEngineTest.php` compares both engines field by field on the golden fleets and
300 generated battles (more with `BATTLE_PARITY_RUNS=5000`).

**Limits of the parity**

* The PHP engine scales fleets above its ship budget (memory dependent, at most 500 000 ships) down proportionally; the Rust
  engine always fights every ship (up to `maxShips`), so for such fights the results agree only statistically.
* `bigshield` and `stun` are ignored by both.
* `rf_stopped` has no PHP-engine equivalent and stays empty. The statistics were defined from the PHP rules (see above).

## What changed against the first Rust library

| | First library (closed source) | `tools/battlerust` |
| --- | --- | --- |
| input values | rounded by PHP (attack to 0.1, hull and shield to integers) | exact PHP floats, no rounding |
| randomness | `rand` crate, unseedable | PHP's `mt_rand`, optional seed, replayable |
| users without losses | omitted from `unitLostPerSideAndUser` / `transportCapacityLostPerSideAndUser` | listed with 0 (PHP side initialises every participant, like the PHP engine) |
| defense rebuild rolls | in the order of the first loss | in unit order of the fleet (as the PHP engine) |
| kills | ships with explosion chance were always counted as `explosion_triggered`; `ship_destroyed` only for defense | armor kills are `ship_destroyed`, explosion rolls `explosion_triggered` (PHP's rules). `BattleEngineRust` adds both to the kill statistics, so the totals are unchanged |
| `rf_stopped` | counted (map of fleet -> unit) | always empty |
| ships per battle | about 12 bytes each, no limit | 16 bytes each (15.7 measured incl. everything), refuses above 60 million, about 1 GB (PHP engine takes over) |
| memory of the result | never freed | `battlerust_free` |
| speed | reference | faster for small fights, equal from about 36 million ships, up to about 1.2-1.3x slower for 0.6-7 million (see PERFORMANCE.md) |

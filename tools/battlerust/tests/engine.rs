//! Engine behaviour: rules, invariants, determinism and the JSON contract.

use battlerust::fight_json;
use serde_json::{json, Value};

fn ship(id: u32, attack: f64, hull: f64, shield: f64, amount: u64, rapid: Value) -> Value {
    json!({"shipid": id, "attack": attack, "hull": hull, "shield": shield, "explode": if id / 100 == 2 { 0.7 } else { 0.0 }, "amount": amount, "rapidfire": rapid})
}

fn fight(input: Value) -> Value {
    serde_json::from_str(&fight_json(&input.to_string()).expect("fight")).expect("result json")
}

fn sum_of(round: &Value, side: &str, stat: &str) -> f64 {
    round[side]["Statistics"][stat]["sum"].as_f64().unwrap()
}

fn amount(round: &Value, side: &str, fleet: &str, unit: &str) -> u64 {
    round[side]["Roundstats"][fleet][unit]["amount"].as_u64().unwrap()
}

fn mixed(seed: u64) -> Value {
    json!({
        "rounds": 6, "seed": seed,
        "attacker": [{"name": 0, "ships": [
            ship(204, 75.0, 560.0, 13.0, 100, json!({"210": 5, "212": 5})),
            ship(205, 225.0, 1400.0, 33.0, 40, json!({"202": 3, "210": 5, "212": 5})),
            ship(207, 1500.0, 8400.0, 260.0, 10, json!({"210": 5, "212": 5})),
        ]}],
        "defender": [{"name": 0, "ships": [
            ship(202, 6.5, 480.0, 12.0, 20, json!({})),
            ship(204, 65.0, 480.0, 12.0, 50, json!({"210": 5})),
            ship(401, 104.0, 240.0, 24.0, 80, json!([])),
            ship(402, 130.0, 240.0, 30.0, 30, json!({})),
        ]}],
    })
}

#[test]
fn an_overwhelming_fleet_wins_in_one_fight() {
    let result = fight(json!({
        "rounds": 6, "seed": 1,
        "attacker": [{"name": 0, "ships": [ship(207, 1000.0, 6000.0, 200.0, 1000, json!({}))]}],
        "defender": [{"name": 0, "ships": [ship(204, 50.0, 400.0, 10.0, 1, json!({}))]}],
    }));
    assert_eq!(result["outcome"], 1);
    let rounds = result["rounds"].as_array().unwrap();
    assert_eq!(rounds.len(), 2);
    assert_eq!(amount(&rounds[1], "defender", "0", "204"), 0);
    assert_eq!(rounds[1]["defender"]["Roundstats"]["0"]["204"]["lost"], 1);
    // the first shot hits the 10 shield and the 400 armor, the 590 left over and the other 999 shots hit a dead ship
    assert_eq!(sum_of(&rounds[1], "attacker", "shield_hit"), 10.0);
    assert_eq!(sum_of(&rounds[1], "attacker", "damage_done"), 400.0);
    assert_eq!(sum_of(&rounds[1], "attacker", "damage_dead"), 999_590.0);
    assert_eq!(sum_of(&rounds[1], "attacker", "ship_destroyed"), 1.0);
    assert_eq!(rounds[1]["attacker"]["Statistics"]["ship_destroyed"]["maps"]["0"]["0"]["207"]["204"], 1);
}

#[test]
fn a_fight_nobody_can_win_runs_all_rounds_and_is_a_draw() {
    // 5 attack against 10 shield: nothing is damaged, the shields hold 50 per fight
    let result = fight(json!({
        "rounds": 6, "seed": 3,
        "attacker": [{"name": 0, "ships": [ship(202, 5.0, 4000.0, 10.0, 10, json!({}))]}],
        "defender": [{"name": 0, "ships": [ship(202, 5.0, 4000.0, 10.0, 10, json!({}))]}],
    }));
    assert_eq!(result["outcome"], 0);
    assert_eq!(result["rounds"].as_array().unwrap().len(), 7);
}

#[test]
fn a_shot_below_one_percent_of_the_shield_bounces() {
    let result = fight(json!({
        "rounds": 1, "seed": 3,
        "attacker": [{"name": 0, "ships": [ship(202, 1.0, 4000.0, 10.0, 4, json!({}))]}],
        "defender": [{"name": 0, "ships": [ship(207, 1.0, 8000.0, 100.0, 4, json!({}))]}],
    }));
    let round = &result["rounds"][1];
    assert_eq!(sum_of(round, "attacker", "shield_bounced"), 4.0);
    assert_eq!(sum_of(round, "attacker", "shield_hit"), 0.0);
}

#[test]
fn same_seed_same_battle_other_seed_other_battle() {
    let a = fight_json(&mixed(42).to_string()).unwrap();
    let b = fight_json(&mixed(42).to_string()).unwrap();
    let c = fight_json(&mixed(43).to_string()).unwrap();
    assert_eq!(a, b);
    assert_ne!(a, c);
}

#[test]
fn without_a_seed_the_generator_is_seeded_from_the_os() {
    let mut input = mixed(0);
    input.as_object_mut().unwrap().remove("seed");
    let a: Value = serde_json::from_str(&fight_json(&input.to_string()).unwrap()).unwrap();
    let b: Value = serde_json::from_str(&fight_json(&input.to_string()).unwrap()).unwrap();
    assert_ne!(a["seed"], b["seed"]);
}

#[test]
fn ships_never_come_back_and_lost_adds_up() {
    let result = fight(mixed(5));
    let rounds = result["rounds"].as_array().unwrap();
    for side in ["attacker", "defender"] {
        for unit in result_units(&rounds[0], side) {
            let mut before = amount(&rounds[0], side, "0", &unit);
            for round in &rounds[1..] {
                let now = amount(round, side, "0", &unit);
                assert!(now <= before);
                assert_eq!(round[side]["Roundstats"]["0"][&unit]["lost"].as_u64().unwrap(), before - now);
                before = now;
            }
        }
    }
}

fn result_units(round: &Value, side: &str) -> Vec<String> {
    round[side]["Roundstats"]["0"].as_object().unwrap().keys().cloned().collect()
}

#[test]
fn all_attack_is_accounted_for() {
    // every shot is either bounced, absorbed by a shield, or hits armor (alive or dead): the four sums add up to
    // attack x (ships alive at the start of the fight + rapid fire re-shots)
    let result = fight(json!({
        "rounds": 6, "seed": 11,
        "attacker": [{"name": 0, "ships": [ship(205, 225.0, 1400.0, 33.0, 40, json!({"202": 3}))]}],
        "defender": [{"name": 0, "ships": [ship(202, 6.5, 480.0, 12.0, 60, json!({}))]}],
    }));
    let rounds = result["rounds"].as_array().unwrap();
    for pair in rounds.windows(2) {
        let alive = amount(&pair[0], "attacker", "0", "205") as f64;
        let rapid = sum_of(&pair[1], "attacker", "rapid_fire_done");
        let fired = sum_of(&pair[1], "attacker", "damage_done")
            + sum_of(&pair[1], "attacker", "damage_dead")
            + sum_of(&pair[1], "attacker", "shield_hit")
            + sum_of(&pair[1], "attacker", "shield_bounced");
        assert!((fired - 225.0 * (alive + rapid)).abs() < 1e-6, "fired {fired} alive {alive} rapid {rapid}");
    }
}

#[test]
fn rapid_fire_tables_may_be_objects_or_empty_arrays() {
    let mut input = mixed(9);
    input["defender"][0]["ships"][2]["rapidfire"] = json!([]);
    input["defender"][0]["ships"][3]["rapidfire"] = json!({});
    assert!(fight_json(&input.to_string()).is_ok());
}

#[test]
fn fleet_names_may_be_numbers_or_strings_and_stay_in_input_order() {
    let result = fight(json!({
        "rounds": 1, "seed": 2,
        "attacker": [
            {"name": 17, "ships": [ship(204, 50.0, 400.0, 10.0, 5, json!({}))]},
            {"name": "b", "ships": [ship(204, 50.0, 400.0, 10.0, 5, json!({}))]},
        ],
        "defender": [{"name": 0, "ships": [ship(204, 50.0, 400.0, 10.0, 5, json!({}))]}],
    }));
    let keys: Vec<&String> = result["rounds"][0]["attacker"]["Roundstats"].as_object().unwrap().keys().collect();
    assert_eq!(keys, ["17", "b"]);
}

#[test]
fn an_empty_side_gives_no_fight() {
    let result = fight(json!({"rounds": 6, "seed": 1, "attacker": [{"name": 0, "ships": [ship(204, 50.0, 400.0, 10.0, 5, json!({}))]}], "defender": []}));
    assert_eq!(result["outcome"], 1);
    assert_eq!(result["rounds"].as_array().unwrap().len(), 1);
}

#[test]
fn the_ship_limit_refuses_oversized_battles() {
    let mut input = mixed(1);
    input["maxShips"] = json!(100);
    let error = fight_json(&input.to_string()).unwrap_err();
    assert!(error.contains("too large"), "{error}");
}

#[test]
fn invalid_input_is_an_error_not_a_panic() {
    assert!(fight_json("not json").is_err());
    assert!(fight_json("{\"attacker\": 3}").is_err());
}

#[test]
fn the_c_abi_round_trips_and_frees() {
    use std::ffi::{CStr, CString};
    let input = CString::new(mixed(42).to_string()).unwrap();
    unsafe {
        let out = battlerust::fight_battle_rounds(input.as_ptr());
        assert!(!out.is_null());
        let text = CStr::from_ptr(out).to_str().unwrap();
        assert_eq!(text, fight_json(&mixed(42).to_string()).unwrap());
        battlerust::battlerust_free(out);
        battlerust::battlerust_free(std::ptr::null_mut());
        assert!(battlerust::fight_battle_rounds(std::ptr::null()).is_null());
        assert!(CStr::from_ptr(battlerust::battlerust_version()).to_str().unwrap().starts_with("1."));
    }
}

/// Fights captured from the PHP engine (tests/Integration/BattleEngineTest.php GOLDEN): the inputs are the exact JSON
/// BattleEngineRust sends (unrounded tech-adjusted values), the expected survivors the PHP engine's results under
/// `mt_srand(seed)`. Defenses are rebuilt by the PHP side after the fight, so only ships are compared.
fn survivors(result: &Value, side: &str) -> Vec<(String, u64)> {
    let last = result["rounds"].as_array().unwrap().last().unwrap();
    last[side]["Roundstats"]["0"].as_object().unwrap().iter().map(|(k, v)| (k.clone(), v["amount"].as_u64().unwrap())).collect()
}

fn with_seed(input: &str, seed: u64) -> String {
    let mut value: Value = serde_json::from_str(input).unwrap();
    value["seed"] = json!(seed);
    value.to_string()
}

#[test]
fn matches_the_php_engine_golden_fleet_a() {
    let input = include_str!("data/fleet_a_seed42.json");
    for (seed, attackers) in [(42u64, [34u64, 36, 10]), (7, [46, 37, 10])] {
        let result: Value = serde_json::from_str(&fight_json(&with_seed(input, seed)).unwrap()).unwrap();
        assert_eq!(result["outcome"], 1, "seed {seed}");
        assert_eq!(result["rounds"].as_array().unwrap().len(), if seed == 42 { 7 } else { 6 }, "seed {seed}");
        let left: Vec<u64> = survivors(&result, "attacker").iter().map(|(_, n)| *n).collect();
        assert_eq!(left, attackers, "seed {seed}");
        assert!(survivors(&result, "defender").iter().all(|(_, n)| *n == 0));
    }
}

#[test]
fn matches_the_php_engine_golden_fleet_d() {
    let input = include_str!("data/fleet_d_seed42.json");
    for (seed, ships) in [(42u64, [("205", 15u64), ("206", 6), ("404", 5), ("407", 1)]), (99, [("205", 16), ("206", 7), ("404", 5), ("407", 1)])] {
        let result: Value = serde_json::from_str(&fight_json(&with_seed(input, seed)).unwrap()).unwrap();
        assert_eq!(result["outcome"], -1, "seed {seed}");
        assert_eq!(result["rounds"].as_array().unwrap().len(), 7 - (seed == 99) as usize, "seed {seed}");
        assert!(survivors(&result, "attacker").iter().all(|(_, n)| *n == 0));
        let defenders = survivors(&result, "defender");
        for (unit, amount) in ships {
            assert_eq!(defenders.iter().find(|(k, _)| k == unit).unwrap().1, amount, "seed {seed} unit {unit}");
        }
    }
}

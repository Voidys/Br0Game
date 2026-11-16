//! The generator against sequences produced by PHP 8.4 itself:
//! `php -r 'mt_srand($seed); for ($i = 0; $i < 8; $i++) echo mt_rand(), ",";'` and friends.

use battlerust::rng::MtRand;

#[test]
fn raw_mt_rand_matches_php() {
    let cases: [(u32, [u32; 8]); 7] = [
        (42, [804318771, 1710563033, 2041643438, 393923207, 1571945013, 1674373667, 1285609310, 1281725962]),
        (0, [1178568022, 1273124119, 1535857466, 1813046880, 1294424481, 1842424189, 1170127713, 1819459251]),
        (1, [895547922, 2141438069, 1546885062, 2002651684, 245631, 275145156, 649254245, 2145423170]),
        (7, [163870807, 488206946, 1674862860, 684987643, 941476641, 2100717673, 1553629643, 978361139]),
        (99, [1443707200, 1551642129, 1048140380, 1786594900, 1772737380, 1576387444, 67530601, 1505405474]),
        (123456789, [1144250204, 2127402830, 1147049625, 28249068, 1094256813, 1554489742, 1532367094, 290152835]),
        (4294967295, [209663185, 239673489, 1959327238, 1208374819, 1694440410, 1130266400, 1675044971, 1654882557]),
    ];
    for (seed, expected) in cases {
        let mut rng = MtRand::new(seed);
        let got: Vec<u32> = (0..8).map(|_| rng.next_php()).collect();
        assert_eq!(got, expected, "seed {seed}");
    }
}

#[test]
fn state_reload_matches_php() {
    // the generator regenerates its block every 624 outputs
    let mut rng = MtRand::new(3);
    for _ in 0..1000 {
        rng.next_php();
    }
    assert_eq!(rng.next_php(), 227081443);

    let mut rng = MtRand::new(2);
    for _ in 0..700 {
        rng.next_php();
    }
    assert_eq!((rng.next_php(), rng.next_php()), (1123497577, 684028604));
}

#[test]
fn ranges_match_php() {
    // mt_rand($min, $max) for (min, max) pairs, three passes over the list, one stream per seed
    let ranges: [(i64, i64); 16] = [
        (0, 0), (0, 1), (1, 2), (0, 9), (60, 80), (1, 100), (0, 299), (0, 1_000_000), (0, 3_000_000),
        (0, 4_294_967_294), (0, 4_294_967_295), (0, 4_294_967_296), (0, 10_000_000_000), (5, 5), (1, 3), (-5, 5),
    ];
    let expected: [(u32, [i64; 48]); 3] = [
        (42, [
            0, 1, 1, 4, 78, 36, 220, 449361, 1094727, 1914837113, 669991378, 179921804, 7156699357, 5, 3, -2,
            0, 0, 2, 6, 67, 12, 299, 49182, 2989238, 3344769, 780932287, 3473799847, 7556460605, 5, 3, -2,
            0, 0, 1, 0, 74, 23, 277, 427319, 751289, 4182248123, 1573512143, 3335906898, 6725748399, 5, 2, -1,
        ]),
        (7, [
            0, 0, 2, 6, 61, 48, 287, 720323, 431588, 1322904761, 2312822158, 3275987920, 39352887, 5, 1, 3,
            0, 0, 1, 0, 77, 52, 208, 672681, 1194349, 2896227127, 1237575930, 3275873524, 3143390775, 5, 2, -2,
            0, 1, 2, 0, 61, 62, 204, 278746, 2143023, 747550921, 2355746254, 3786594083, 5108625140, 5, 3, 3,
        ]),
        (4294967295, [
            0, 0, 1, 9, 63, 1, 142, 761805, 2050304, 1217888032, 4163996287, 3913620362, 5245816670, 5, 1, -1,
            0, 1, 2, 8, 71, 98, 81, 274849, 684629, 1217802284, 4009496932, 2402558842, 7264161744, 5, 2, 0,
            0, 1, 2, 4, 72, 74, 225, 928120, 430466, 4273850057, 2493314799, 4153930152, 6459441254, 5, 3, -3,
        ]),
    ];
    for (seed, values) in expected {
        let mut rng = MtRand::new(seed);
        let mut got = Vec::new();
        for _ in 0..3 {
            for (min, max) in ranges {
                got.push(rng.range(min, max));
            }
        }
        assert_eq!(got, values, "seed {seed}");
    }
}

#[test]
fn rand_swaps_reversed_bounds_like_php() {
    // mt_srand(5); rand(1, 0); rand(10, 3) => 1, 9
    let mut rng = MtRand::new(5);
    assert_eq!((rng.rand(1, 0), rng.rand(10, 3)), (1, 9));
}

#[test]
fn a_degenerate_range_still_consumes_one_draw() {
    // mt_srand(42); mt_rand(0, 0); mt_rand() is the second raw output
    let mut rng = MtRand::new(42);
    assert_eq!(rng.range(0, 0), 0);
    assert_eq!(rng.draws(), 1);
    assert_eq!(rng.next_php(), 1710563033);
}

#[test]
fn negative_seeds_are_truncated_to_32_bits() {
    let mut a = MtRand::from_php_seed(-1);
    let mut b = MtRand::new(4294967295);
    assert_eq!(a.next_u32(), b.next_u32());
}

#[test]
fn precomputed_ranges_give_the_same_values_and_consume_the_same_draws() {
    use battlerust::rng::Range32;
    for umax in [0u32, 1, 2, 3, 6, 7, 9, 20, 299, 1000, 65535, 999_999, 3_000_000, 1 << 31, 4_000_000_000, 4_294_967_294, u32::MAX] {
        let range = Range32::new(umax);
        let mut a = MtRand::new(umax ^ 0xabcd);
        let mut b = a.clone();
        for _ in 0..2000 {
            assert_eq!(a.draw(&range) as i64, b.range(0, umax as i64), "umax {umax}");
        }
        assert_eq!(a.draws(), b.draws(), "umax {umax}");
    }
}

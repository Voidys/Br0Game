//! PHP's `mt_rand()` / `rand()`, bit for bit.
//!
//! PHP (>= 7.1) implements `mt_rand()` with the reference MT19937 generator (`MT_RAND_MT19937` mode), seeded by
//! `mt_srand($seed)` with the 32-bit truncated seed. `mt_rand()` without arguments is the 32-bit output shifted
//! right by one; `mt_rand($min, $max)` (and `rand($min, $max)`, which is an alias that swaps reversed bounds) maps
//! the range with unbiased rejection sampling: one 32-bit output per attempt (two combined for ranges wider than
//! 2^32). This module reproduces exactly that, including the draw that a degenerate range such as `rand(0, 0)`
//! still consumes, so a battle seeded here consumes the same stream as the PHP engine seeded with `mt_srand()`.

const N: usize = 624;
const M: usize = 397;

/// A uniform range `0..=umax` (32-bit) with its constants precomputed: the rejection limit and a multiplicative
/// inverse that replaces the modulo (Lemire's fastmod). Gives exactly the values of [`MtRand::range`]; worth it
/// where the same range is drawn millions of times (victim selection of a big battle).
#[derive(Clone, Copy)]
pub struct Range32 {
    /// `umax + 1`, 0 for the full 32-bit range
    n: u32,
    limit: u32,
    magic: u64,
}

impl Range32 {
    /// The range `0..=umax`.
    pub fn new(umax: u32) -> Self {
        if umax == u32::MAX {
            return Range32 { n: 0, limit: 0, magic: 0 };
        }
        let n = umax + 1;
        let limit = u32::MAX - (u32::MAX % n) - 1;
        let magic = if n.is_power_of_two() { 0 } else { u64::MAX / n as u64 + 1 };
        Range32 { n, limit, magic }
    }
}

impl Range32 {
    /// The value a first output `result` maps to, None when the rejection sampling would draw again.
    #[inline]
    pub fn map(&self, result: u32) -> Option<u32> {
        if self.n == 0 {
            return Some(result);
        }
        if self.magic == 0 {
            return Some(result & (self.n - 1));
        }
        if result > self.limit {
            return None;
        }
        let low = self.magic.wrapping_mul(result as u64);

        Some(((low as u128 * self.n as u128) >> 64) as u32)
    }
}

/// MT19937 with PHP's seeding and range mapping. Counts every 32-bit output it hands out ([`MtRand::draws`]).
#[derive(Clone)]
pub struct MtRand {
    state: [u32; N],
    index: usize,
    draws: u64,
}

impl MtRand {
    /// Seeds like `mt_srand($seed)`: only the low 32 bits of the seed count.
    pub fn new(seed: u32) -> Self {
        let mut state = [0u32; N];
        state[0] = seed;
        for i in 1..N {
            let prev = state[i - 1];
            state[i] = 1_812_433_253u32
                .wrapping_mul(prev ^ (prev >> 30))
                .wrapping_add(i as u32);
        }
        MtRand { state, index: N, draws: 0 }
    }

    /// Seeds like `mt_srand($seed)` for a PHP integer (negative values are truncated to 32 bits).
    pub fn from_php_seed(seed: i64) -> Self {
        Self::new(seed as u32)
    }

    /// Regenerates the 624-word block (the standard MT19937 twist, `MT_RAND_MT19937` variant of PHP).
    fn reload(&mut self) {
        let s = &mut self.state;
        let twist = |m: u32, u: u32, v: u32| -> u32 {
            m ^ (((u & 0x8000_0000) | (v & 0x7fff_ffff)) >> 1) ^ ((0u32.wrapping_sub(v & 1)) & 0x9908_b0df)
        };
        for i in 0..(N - M) {
            s[i] = twist(s[i + M], s[i], s[i + 1]);
        }
        for i in (N - M)..(N - 1) {
            s[i] = twist(s[i + M - N], s[i], s[i + 1]);
        }
        s[N - 1] = twist(s[M - 1], s[N - 1], s[0]);
        self.index = 0;
    }

    /// The raw output `ahead` places after the next one, without consuming anything; None when the block has to be
    /// regenerated first. Only used to prefetch memory for shots that will probably follow.
    #[inline]
    pub fn peek(&self, ahead: usize) -> Option<u32> {
        let at = self.index + ahead;
        if at >= N {
            return None;
        }
        let mut y = self.state[at];
        y ^= y >> 11;
        y ^= (y << 7) & 0x9d2c_5680;
        y ^= (y << 15) & 0xefc6_0000;
        Some(y ^ (y >> 18))
    }

    /// The next raw tempered 32-bit output (what `mt_rand()` returns before its `>> 1`).
    pub fn next_u32(&mut self) -> u32 {
        if self.index >= N {
            self.reload();
        }
        let mut y = self.state[self.index];
        self.index += 1;
        self.draws += 1;
        y ^= y >> 11;
        y ^= (y << 7) & 0x9d2c_5680;
        y ^= (y << 15) & 0xefc6_0000;
        y ^ (y >> 18)
    }

    /// Number of 32-bit outputs consumed since seeding (a PHP caller replays them with `mt_rand()` to continue the stream).
    pub fn draws(&self) -> u64 {
        self.draws
    }

    /// `mt_rand()` without arguments.
    pub fn next_php(&mut self) -> u32 {
        self.next_u32() >> 1
    }

    /// Uniform value in `0..=umax`, PHP's `php_random_range32` (always consumes at least one output).
    fn range32(&mut self, umax: u32) -> u32 {
        let mut result = self.next_u32();
        if umax == u32::MAX {
            return result;
        }
        let umax = umax + 1;
        if umax & (umax - 1) == 0 {
            return result & (umax - 1);
        }
        let limit = u32::MAX - (u32::MAX % umax) - 1;
        while result > limit {
            result = self.next_u32();
        }
        result % umax
    }

    /// Uniform value of a precomputed [`Range32`]; consumes the same outputs as [`MtRand::range`] would.
    #[inline]
    pub fn draw(&mut self, range: &Range32) -> u32 {
        let mut result = self.next_u32();
        if range.n == 0 {
            return result;
        }
        if range.magic == 0 {
            return result & (range.n - 1);
        }
        while result > range.limit {
            result = self.next_u32();
        }
        let low = range.magic.wrapping_mul(result as u64);

        ((low as u128 * range.n as u128) >> 64) as u32
    }

    /// Uniform value in `0..=umax` for ranges wider than 32 bits, PHP's `php_random_range64`.
    fn range64(&mut self, umax: u64) -> u64 {
        // PHP writes `(php_mt_rand() << 32) | php_mt_rand()`; compilers evaluate the right operand first, so the first
        // output is the LOW half (verified against PHP 8.4: seed 42, mt_rand(0, 4294967296) = 179921804)
        let draw = |rng: &mut MtRand| -> u64 {
            let low = rng.next_u32() as u64;
            let high = rng.next_u32() as u64;
            (high << 32) | low
        };
        let mut result = draw(self);
        if umax == u64::MAX {
            return result;
        }
        let umax = umax + 1;
        if umax & (umax - 1) == 0 {
            return result & (umax - 1);
        }
        let limit = u64::MAX - (u64::MAX % umax) - 1;
        while result > limit {
            result = draw(self);
        }
        result % umax
    }

    /// `mt_rand($min, $max)` for `$max >= $min`.
    pub fn range(&mut self, min: i64, max: i64) -> i64 {
        debug_assert!(max >= min);
        let umax = (max as u64).wrapping_sub(min as u64);
        let offset = if umax > u32::MAX as u64 {
            self.range64(umax)
        } else {
            self.range32(umax as u32) as u64
        };
        (min as u64).wrapping_add(offset) as i64
    }

    /// `rand($min, $max)`: like [`MtRand::range`], but reversed bounds are swapped (as PHP does).
    pub fn rand(&mut self, min: i64, max: i64) -> i64 {
        if max < min {
            self.range(max, min)
        } else {
            self.range(min, max)
        }
    }
}

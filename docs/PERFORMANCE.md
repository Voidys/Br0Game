# Performance

Measurements and changes of the performance pass (2026-11-15), done after the game logic bug sweep
([GAMEBUGS.md](GAMEBUGS.md)). Game results are unchanged: every change was checked with a full crawl diff (HTML identical
except volatile values) and, for the statistics and resource routines, by running old and new code side by side on a
cloned database and comparing the written rows.

## Method

**Pages.** A PHP built-in server runs inside the DDEV web container in production mode (`APP_ENV=prod`,
`APP_DEBUG=0`, OPcache on, no profiler, prod cache warmed), so the numbers show the application and not the Symfony
debug toolbar. A script logs in as the admin account, enters universe 1, opens the admin area, then requests each page
twice to warm up and 15 times measured (curl `time_total`; median and p90). Queries and database time per request come
from MySQL's `performance_schema.events_statements_summary_by_digest`, truncated before each page. Each scenario ran
twice; the table shows the mean of the two medians. Same database content before and after (dev data: 4 players,
10 planets, about 240 messages). Variation between passes: about ±3 %.

**Game routines.** A CLI script boots the Symfony kernel in prod mode against a throwaway clone of the database seeded
with 1000 players, 5000 planets, 40 alliances, 800 fleets (600 due), 100k messages and tutorial rows, and times each
routine with `hrtime()` and the game's query counter (median of 3 runs before, 5 runs after).

## Pages: before → after

| Page | Median before | Median after | Change | Queries before → after | DB time before → after | With deploy tuning |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `/` | 14.9 ms | 13.3 ms | -10 % | 8 → 6 | 1.76 → 1.34 ms | 11.0 ms |
| `/?page=news` | 14.9 ms | 13.4 ms | -10 % | 9 → 7 | 1.72 → 1.38 ms | 10.7 ms |
| `/?page=hoF` | 14.3 ms | 12.9 ms | -9 % | 7 → 5 | 1.39 → 1.04 ms | 10.0 ms |
| `/account` | 18.8 ms | 16.4 ms | -13 % | 14 → 10 | 2.81 → 2.11 ms | 13.6 ms |
| `/game?page=overview` | 28.3 ms | 23.5 ms | -17 % | 48 → 36 | 8.38 → 6.08 ms | 20.0 ms |
| `/game?page=buildings` | 26.6 ms | 22.9 ms | -14 % | 40 → 28 | 7.27 → 4.95 ms | 19.5 ms |
| `/game?page=research` | 26.9 ms | 22.1 ms | -18 % | 40 → 28 | 7.27 → 4.98 ms | 19.0 ms |
| `/game?page=shipyard` | 25.9 ms | 21.6 ms | -17 % | 39 → 27 | 7.00 → 4.87 ms | 18.2 ms |
| `/game?page=shipyard&mode=defense` | 26.0 ms | 21.8 ms | -16 % | 39 → 27 | 7.10 → 4.83 ms | 18.1 ms |
| `/game?page=resources` | 25.8 ms | 21.5 ms | -17 % | 39 → 27 | 7.04 → 4.76 ms | 17.9 ms |
| `/game?page=empire` | 50.0 ms | 26.1 ms | -48 % | 116 → 37 | 21.10 → 6.22 ms | 23.6 ms |
| `/game?page=galaxy` | 35.5 ms | 24.5 ms | -31 % | 71 → 33 | 12.84 → 6.32 ms | 21.1 ms |
| `/game?page=fleetTable` | 27.0 ms | 22.4 ms | -17 % | 43 → 31 | 7.65 → 5.30 ms | 19.5 ms |
| `/game?page=messages` | 29.6 ms | 25.1 ms | -15 % | 49 → 37 | 9.70 → 7.35 ms | 22.9 ms |
| `/game?page=messages&category=0` | 44.2 ms | 38.9 ms | -12 % | 50 → 38 | 11.18 → 8.68 ms | 36.2 ms |
| `/game?page=statistics` | 28.5 ms | 22.6 ms | -21 % | 47 → 31 | 8.82 → 5.50 ms | 20.0 ms |
| `/game?page=alliance` | 26.2 ms | 21.8 ms | -17 % | 41 → 29 | 7.46 → 5.03 ms | 18.6 ms |
| `/game?page=techtree` | 27.1 ms | 22.5 ms | -17 % | 39 → 27 | 7.11 → 4.67 ms | 19.3 ms |
| `/game?page=information&id=1` | 20.8 ms | 18.4 ms | -11 % | 23 → 18 | 4.29 → 3.38 ms | 14.9 ms |
| `/game?page=marketPlace` | 29.0 ms | 24.5 ms | -16 % | 48 → 36 | 8.96 → 6.70 ms | 21.7 ms |
| `/game?page=settings` | 26.9 ms | 22.5 ms | -17 % | 41 → 29 | 7.35 → 5.02 ms | 19.0 ms |
| `/game?page=battleSimulator` | 26.1 ms | 21.4 ms | -18 % | 39 → 27 | 7.11 → 4.80 ms | 18.2 ms |
| `/game?page=playerCard&id=2` | 22.9 ms | 20.8 ms | -9 % | 31 → 26 | 5.81 → 4.69 ms | 17.5 ms |
| `/game?page=records` | 27.6 ms | 23.3 ms | -15 % | 44 → 32 | 8.07 → 5.62 ms | 20.1 ms |
| `/admin?page=overview` | 20.9 ms | 17.8 ms | -15 % | 22 → 13 | 4.31 → 2.84 ms | 14.8 ms |
| `/admin?page=flyingFleet` | 19.9 ms | 16.9 ms | -15 % | 19 → 10 | 4.07 → 2.45 ms | 14.1 ms |

"With deploy tuning" adds an authoritative Composer class map and OPcache preloading on top of the code changes (see below).

Summary: every page got faster; a typical game page went from about 26 ms / 40 queries to about 22 ms / 28 queries
(-15 to -20 %), the empire overview from 50 ms / 116 queries to 26 ms / 37 queries (-48 %), the galaxy from 36 ms / 71
queries to 25 ms / 33 queries (-33 %), public pages by about 10 %.

## Game routines: before → after

| Routine | Before | After | Change |
| --- | ---: | ---: | ---: |
| Statistics build (`Statbuilder::generateStats`) | 600.6 ms, 1090 queries | 149.5 ms, 52 queries | -75 % |
| Records (`Statbuilder::buildRecords`) | 975.9 ms, 3281 queries | 35.2 ms, 10 queries | -96 % |
| Statistics cronjob, total | 1559.5 ms, 4375 queries | 213.2 ms, 66 queries | -86 % |
| Fleet events, 600 due transport/stay fleets | 2686.9 ms, 9012 queries | 2048.0 ms, 8651 queries | -24 % |
| Resource update, 5000 planets, no save | 2581.0 ms, 10000 queries | 1129.8 ms, 5000 queries | -56 % |
| Resource update, 1000 planets, with save | 951.1 ms, 3000 queries | 644.1 ms, 2000 queries | -32 % |
| Resource update, 1000 planets, no eco hash | 464.1 ms, 2000 queries | 216.9 ms, 1000 queries | -53 % |
| Daily cronjob (sum of steps) | about 145 ms | about 138 ms | unchanged (runs once a day) |
| Cleaner cronjob | 12.4 ms | 12.4 ms (163 ms with 100k seeded messages) | unchanged, data dependent |

## What changed

Per request (every page):
- The global and universe configuration is read once per request instead of twice (`App\Runtime\GameConfig` reuses the
  rows `ConfigRegistry` loaded): -2 queries on every page.
- Session bookkeeping (`Session::save()`): the session row, `onlinetime`/`user_lastip` and the multi-IP check are written
  only when something changed or at most every 30 s (online displays may lag up to 30 s): -4 to -5 queries.
- The ban-expiry `UPDATE` runs only when a ban actually expired.
- The pending-migration scan is cached (`var/game/cache.migrations.php`, rebuilt when `install/migrations` changes).
- Tutorial bookkeeping syncs missing rows at most once a minute per player; the tutorial box is skipped when the module is
  off; alliance application/diplomacy counters are skipped for players without an alliance; the account id is looked
  up once per controller.
- New indexes (migration 1794787218): `accounts_to_users` (user/account), the tutorial tables, and
  `users_to_messages (user_id, message_unread, deleted_at)` for the unread-message count in the per-request user query
  (100 rows examined → 1 on the seeded data; full scans of 13k–22k rows → index lookups for the tutorial queries).

Pages:
- Empire: the chart points are one query instead of one per data point (65 → 1), fleets one query instead of one per
  planet.
- Galaxy: colonisation limits are checked once per distance tier (15 → 4 counts), the noob-protection check reads the
  outlaw statistic only when that rule is enabled, planet counts only when they can matter.

Game routines:
- Statistics: per-user statistic rows written in batches of 200, record maxima one query per type, record rows batched,
  level costs memoised (same float summation order), redundant `DISTINCT` removed.
- Resource update: repair job and wreck field check share one `SELECT … FOR UPDATE`; production and storage formulas
  are compiled once per process into closures instead of `eval()` on every call.
- Fleet events: language packs loaded once per language code and reused.

## Battle engines

The game now has two engines: the Rust engine and the PHP engine as fallback (`BattleHandler`). Until GB-80 the Rust
library was never found (wrong path), so every battle ran on the PHP engine. The Julia engine (a separate HTTP service)
and the in-process FFI call of the Rust library were removed: the Rust engine always runs through `bin/battlerust`,
a 16 KB launcher (`tools/battlerust-cli/`, built with `tools/battlerust-cli/build.sh` in a throwaway Ubuntu 20.04 container, glibc ≥ 2.4)
that loads `bin/libbattlerust.so` and exchanges the battle JSON over stdin/stdout. No PHP extension is needed; Linux
x86_64 only (elsewhere the PHP engine is used). The Rust source is not public; only the compiled library is shipped.

Same fights through the engines (simulation mode, techs 10/10/10, prod kernel in the web container; median). The FFI
column is the in-process call measured before it was removed:

| Fight | Units | PHP engine | Rust (FFI, removed) | Rust (launcher) | Rust speed-up |
| --- | ---: | ---: | ---: | ---: | ---: |
| S: 50 light fighters + 20 cruisers vs 100 rocket launchers + 50 light lasers | 220 | 0.7 ms | 1.5 ms | 2.7 ms | – (PHP faster) |
| M: 5k cruisers + 2k battleships vs 20k RL + 10k LL + 2k gauss | 39 000 | 318 ms | 6.1 ms | 8.3 ms | ≈ 40× |
| L: 100k cruisers + 30k battleships vs 300k RL + 150k LL + 20k gauss | 600 000 | 6 495 ms | 57.3 ms | 58.4 ms | ≈ 110× |
| XL: 900k cruisers + 100k battleships vs 1.5M RL + 800k LL + 100k gauss | 3 400 000 | 15 987 ms¹ | 468 ms | 504 ms | ≈ 32× |

¹ The PHP engine runs fights above 500 000 units on proportionally scaled counts (GB-21), so XL is an approximation
there; without scaling it would need several GB of memory.

Results agree: average survivors per fight differ by up to about 4 % between the engines (random battles, 3–5 runs),
and the outcome is the same in every run. The launcher costs about 1.5–2.5 ms per battle (process start); from
medium-sized fights on it is as fast as the in-process call. A simulator run through the web game (39 000 units) took
90 ms including the request and stored a `rust` report that renders normally.

## Deployment settings (not code)

Measured on top of the code changes, same harness:

| Setting | Effect per request |
| --- | --- |
| `composer install --no-dev --optimize-autoloader --classmap-authoritative` | about -2 to -2.5 ms |
| OPcache preload of the container (`opcache.preload=config/preload.php`) | about -2 to -2.5 ms |
| both | about -3 ms (game page 22.7 → 19.5 ms, start page 13.2 → 11.0 ms) |

Both are documented in the README (production install). They are not enabled in the DDEV development setup, where new
classes must autoload without re-dumping and code changes must apply without restarting PHP.

## Not changed / follow-ups

- Spy reports in the message list are rendered on every view (about 0.8 ms each including the whitespace filter;
  20 reports ≈ 15 ms on `messages&category=0`). Caching the rendered HTML needs a key over template, language, theme
  and timezone.
- `planet_wreckfield` is still read twice on the buildings page (repair order + navigation), and the tutorial support
  classes run one extra main-planet query.
- `Daily::emptyInactiveAllianceAndBuddy` is an N+1 (about 600 queries for 200 players); left as is because it runs once
  a day.
- `log_fleets` has no index on `start_time` for the daily cleanup; skipped because it would add write cost to every
  fleet log entry.
- PHP boot (Symfony kernel + game bootstrap) is now the largest share of a request (about 15 of 22 ms); the deployment
  settings above are the remaining cheap win.

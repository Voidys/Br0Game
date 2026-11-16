# Concurrency load test

Dev tool for the DDEV site (never point it at production). Creates test players with rich planets and fleets, schedules a
few hundred short flights (transport / espionage / attack between the test players) that arrive in clusters of the same second,
and lets many logged-in sessions hammer the in-game pages and dispatch fleets through the real 3-step form while the events
become due. Reports requests, HTTP 5xx / SQL errors in the pages, latencies (p50/p95/p99) and, from MySQL, deadlocks, lock
timeouts and row lock waits; leaves the InnoDB deadlock graph and the game.log error lines of the run next to the result.

Files: `seed.php` (players, waves, reset), `loadtest.mjs` (the sessions, Node 18+ on the host, no dependencies), `run.sh` (one
complete run).

## Run

From the repo root, DDEV running, Node on the host:

```sh
OUT=/tmp/lt tools/loadtest/run.sh <label> [players=60] [sessions=60] [duration=180] [flights=600]
```

`run.sh` removes old test players (`seed.php reset`), seeds `players` players (`lt_1..lt_n`, mail `lt_n@loadtest.invalid`,
password `loadtest`, universe 1), schedules the flights, runs the sessions and collects `<label>.json` (client numbers),
`.status.before/.after` (MySQL counters), `.deadlock.txt`, `.gamelog.errors`, `.fleets`. Deadlocks counted by MySQL are
`lock_deadlocks` after minus before (it is a lifetime counter).

For a reproducible comparison restore the same database before each run: `RESTORE_DUMP=/path/to/dump.sql.gz` (a
`ddev export-db --file=...` taken beforehand) makes `run.sh` import it first.

## Reset

```sh
ddev exec "env APP_ENV=test DATABASE_URL='mysql://db:db@db:3306/db?serverVersion=8.4.13&charset=utf8mb4' php tools/loadtest/seed.php reset"
```

deletes every `lt_*` player with planets, fleets, messages and accounts (via the game's own player deletion). Bots and other
players keep running while a test runs, and a dispatched fleet stays in flight afterwards: for an exact return to the previous
state use `ddev export-db` before and `ddev import-db` after.

## Notes

- PHP-FPM in DDEV starts with 8 workers; the numbers in docs/CHANGELOG.md were taken with 32
  (`sudo sed -i 's/^pm.max_children = .*/pm.max_children = 32/' /etc/php/8.4/fpm/pool.d/www.conf && sudo service php8.4-fpm reload`
  inside `ddev ssh`, gone at the next restart). `SET GLOBAL innodb_print_all_deadlocks = ON` writes every deadlock graph to the MySQL log.
- Test accounts must carry the game's own password cost (the seed uses `AccountUtil::cryptPassword()`): a weaker hash is rehashed at
  the first login and Symfony then drops that session.
- The site runs in dev mode (profiler, debug log) like the rest of the DDEV setup, so absolute latencies are higher than in production.

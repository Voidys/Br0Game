#!/usr/bin/env bash
# One load test run: reset to a clean state, seed, run the sessions, collect the InnoDB / game.log numbers.
# Usage (from the repo root): tools/loadtest/run.sh <label> [players=60] [sessions=60] [duration=180] [flights=600]
# Results go to $OUT (default: tools/loadtest/results, gitignored by the caller's choice) as <label>.*
# Needs DDEV running and Node 18+ on the host. Resets the DB from $RESTORE_DUMP when set (ddev export-db file), otherwise
# only removes the test players (seed.php reset) before and after.
set -euo pipefail
cd "$(dirname "$0")/../.."
LABEL=${1:?label}; PLAYERS=${2:-60}; SESSIONS=${3:-60}; DURATION=${4:-180}; FLIGHTS=${5:-600}
OUT=${OUT:-tools/loadtest/results}; mkdir -p "$OUT"
BASE=${BASE:-https://br0game.ddev.site}
SEED="env APP_ENV=test DATABASE_URL='mysql://db:db@db:3306/db?serverVersion=8.4.13&charset=utf8mb4' php tools/loadtest/seed.php"

status() {
  ddev mysql -N -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_row_lock_time_avg','Innodb_row_lock_time_max')" 2>/dev/null | tr '\t' '='
  ddev mysql -N -e "SELECT NAME, COUNT FROM information_schema.INNODB_METRICS WHERE NAME IN ('lock_deadlocks','lock_timeouts')" 2>/dev/null | tr '\t' '='
}

if [ -n "${RESTORE_DUMP:-}" ]; then ddev import-db --file="$RESTORE_DUMP" >/dev/null 2>&1; else ddev exec "$SEED reset" >/dev/null; fi
ddev exec "$SEED players $PLAYERS"
for i in 1 2 3; do curl -sk -o /dev/null "$BASE/"; done; sleep 3
ddev mysql -N -e 'SELECT u.username, u.id, p.galaxy, p.`system`, p.planet FROM uni1_users u JOIN uni1_planets p ON p.id = u.id_planet WHERE u.username LIKE "lt\_%" ORDER BY u.id' > "$OUT/$LABEL.players.tsv"

LOG_LINES=$(wc -l < var/log/game.log)
status > "$OUT/$LABEL.status.before"
# a fresh fleet schedule right before the sessions start (arrivals 15..195 s from now, clusters of the same second)
ddev exec "$SEED waves $FLIGHTS 15 195 15"
node tools/loadtest/loadtest.mjs --base "$BASE" --players "$OUT/$LABEL.players.tsv" --sessions "$SESSIONS" --duration "$DURATION" --think 150 --out "$OUT/$LABEL.json" > "$OUT/$LABEL.stdout" 2>"$OUT/$LABEL.stderr" || true
status > "$OUT/$LABEL.status.after"

# game.log: error level lines written during the run (the file also holds the debug channel in dev)
tail -n +"$((LOG_LINES + 1))" var/log/game.log | grep -E 'ERROR|CRITICAL|\[(error|critical|alert|emergency)\]|Deadlock|Uncaught' > "$OUT/$LABEL.gamelog.errors" || true
ddev mysql -e "SHOW ENGINE INNODB STATUS\G" 2>/dev/null | sed -n '/LATEST DETECTED DEADLOCK/,/^TRANSACTIONS/p' > "$OUT/$LABEL.deadlock.txt" || true
ddev mysql -N -e "SELECT COUNT(*) FROM uni1_fleets; SELECT COUNT(*) FROM uni1_fleet_event; SELECT COUNT(*) FROM uni1_fleet_event WHERE \`lock\` IS NOT NULL" > "$OUT/$LABEL.fleets" 2>/dev/null || true
paste -d' ' "$OUT/$LABEL.status.before" "$OUT/$LABEL.status.after"
echo "gamelog error lines: $(wc -l < "$OUT/$LABEL.gamelog.errors")"

# Database concurrency

Every in-game request claims due fleet events and resolves them (`GameBootstrap::processFleetEvents()`), locks the player and the
active planet row, and fleet dispatches, queues and bots write in their own transactions. Without care these collide: deadlocks
(MySQL error 1213) and lock wait timeouts (1205) show up as error pages.

## Isolation level: READ COMMITTED

`App\Game\Core\Database` runs `SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED` right after connecting (Symfony's DBAL
connection is shared with the game, so it applies to both). Under the MySQL default REPEATABLE READ a range scan such as the
event claim (`UPDATE fleet_event ... WHERE lock IS NULL AND time <= ? AND universe = ?`) or `SELECT ... FROM fleets ... FOR UPDATE`
takes gap/next-key locks that block unrelated inserts (new fleets, new events) and produced lock-order inversions with the
user/planet row locks. READ COMMITTED locks only the rows that match.

The game's rule is "lock the rows (`FOR UPDATE`) first, then calculate and write": locking reads and `UPDATE ... SET x = x + y`
always see the latest committed data in both levels, so the level does not change what they compute. What changes is that a plain
`SELECT` inside a transaction sees data committed meanwhile (no frozen snapshot). No game code reads a value twice in one
transaction and expects the same answer.

**Binary logging requires `binlog_format=ROW`.** MySQL refuses InnoDB writes under READ COMMITTED when `binlog_format=STATEMENT`
(error 1665, "Cannot execute statement: impossible to write to binary logging since BINLOG_FORMAT = STATEMENT and
transaction isolation level is READ COMMITTED"). MySQL 8.x defaults to ROW, as does DDEV; check the production server:

```sql
SHOW VARIABLES LIKE 'binlog_format';   -- must be ROW (or log_bin = OFF)
```

If it says STATEMENT or MIXED (MariaDB defaults to MIXED), set `binlog_format = ROW` in `my.cnf`
(`SET GLOBAL binlog_format = 'ROW'` until the next restart). MariaDB accepts READ COMMITTED with MIXED, but ROW is the safe choice.
Servers without binary logging are not affected.

## Repeating deadlocked transactions

`Database::transactional(callable $fn, int $attempts = 3, int $backoffMs = 25)` starts a transaction, runs `$fn`, commits, and on
1213/1205 rolls back, waits a short jittered back-off (attempt x backoff, +-50%) and runs `$fn` again. Other exceptions roll back
and are rethrown at once, so is the retryable one after the last attempt. `Database::isRetryable()` / `errorNumber()` classify
an exception (own `Exception` with the PDO error as `previous`, Doctrine exceptions, `PDOException`).

The closure is run again from the start, so it must be safe to repeat:

- only database work inside: no mail, Discord, output, file writes, session writes (do those before or after the call);
- in-memory state the closure changes must be reset at its start (`FleetDispatch` snapshots the planet row, storage, mission
  and ACS group before the first attempt and restores them in each attempt).

Used for the fleet event claim and the fleet handler run (`GameBootstrap::processFleetEvents()`, `BotEngine::processFleetEvents()`,
the handler run also gives its event locks back when every attempt failed) and the fleet dispatch (`FleetDispatch::dispatch()`).
When the claim stays deadlocked after all attempts, the request goes on without events and reports the error to Discord as before
(the next request claims them).

## Measuring

`tools/loadtest/` (see its README) runs many logged-in players against a DDEV site while fleet events fall due in the same seconds,
and prints deadlocks (`information_schema.INNODB_METRICS`), row lock waits and latencies. Deadlock graphs: set
`innodb_print_all_deadlocks=ON` and look at `SHOW ENGINE INNODB STATUS` / the MySQL error log.

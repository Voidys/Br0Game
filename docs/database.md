# Database access

All game queries go through `App\Game\Core\Database` (a service, never `Database::get()`), a wrapper over Symfony's
Doctrine DBAL connection (same PDO handle, so transactions, `lastInsertId` and the sql_mode are shared).

## Writing queries

**Static SQL with bound parameters** is the default and stays the right tool when the statement text is fixed:

```php
$row = $this->db->selectSingle('SELECT id, username FROM %%USERS%% WHERE id = :id;', [':id' => $id]);
$this->db->update('UPDATE %%PLANETS%% SET metal = metal + :m WHERE id = :id;', [':m' => $m, ':id' => $id]);
```

- Table names are placeholders `%%USERS%%` (keys of `src/Game/Support/tables.php`); the wrapper replaces them with the prefixed
  names (`uni1_users`) and quotes the reserved words `system` and `rank`.
- Values are always parameters (`:name`), never concatenated; `:limit`/`:offset` are bound as integers.
- `select`/`selectSingle`/`insert`/`update`/`delete`/`replace` check the verb. Queries that cannot be expressed otherwise (window
  functions and derived tables in the message list, `UPDATE ... JOIN`, `UPDATE ... ORDER BY` with `@i` rank counters in the
  statistics, multi-row `INSERT` chunks, `ON DUPLICATE KEY UPDATE`) stay static SQL.

**Use the QueryBuilder when the SQL text itself is assembled at runtime**: optional filters, a column list from the game's
resource table, an `IN` list, a sort order chosen by the code, `LIMIT`/`OFFSET` for paging. Do not build SQL with `.`/`implode()`.

```php
$qb = $db->qb()                                   // Doctrine\DBAL\Query\QueryBuilder
    ->select('p.id', 'p.`system`')                // quote `system` and `rank` yourself
    ->from($db->table('PLANETS'), 'p')            // table('PLANETS') = prefixed table name
    ->where('p.id_owner = :owner')
    ->setParameter('owner', $userId);
if ($onlyMoons) {
    $qb->andWhere('p.planet_type = 3');
}
$ids = $db->fetchAll($qb->orderBy('p.id', 'ASC')->setFirstResult($offset)->setMaxResults(25));
```

- Run builders through `fetchAll()`, `fetchRow()` (false when empty), `fetchValue()` or `execute()` (insert/update/delete, returns the
  affected rows): they keep the query counter, the DB time, `rowCount()`/`lastInsertId()` and the debug log of the wrapper and throw
  `Exception` with the query text on errors. `getSQL()` shows the generated statement.
- `IN` lists: `->where('id IN (:ids)')->setParameter('ids', $ids, ArrayParameterType::INTEGER)` (or `STRING`), never `implode()`.
- Column names and sort expressions must be fixed code (`$resource[...]`, `$reslist`, `AllianceRights::AVAILABLE`, explicit maps), never
  request input; directions are limited to `ASC`/`DESC`. Values are bound with `setParameter()`.
- A DBAL 4 `UPDATE` builder has no table alias and no join: write unqualified columns, or use a subselect for the join condition.
- Keep a secondary sort column when the primary one can tie (`ORDER BY units DESC, time ASC`), exactly like the SQL it replaced.

**`Database::updateRows($tableKey, $values, $where, $expressions = [], $increments = [])`** covers the common "collect the columns to
change, then UPDATE" case (editor saves, settings, resets): `$values` are bound (`col = :v`), `$expressions` are fixed SQL such as
`"''"`, `$increments` are `col = col +/- :amount` (negative amount subtracts), `$where` is a list of `column => value` equalities.
Column names must be plain identifiers (letters, digits, underscore) or it throws.

Tests for the converted families (`tests/Integration/QueryBuilderQueriesTest.php`) keep the old SQL as oracle and compare rows,
order of ties and paging.

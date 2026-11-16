<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\SearchController;
use App\Game\Core\BattleHallFilter;
use App\Game\Core\Player\PlanetQueries;
use App\Game\Core\PlayerUtil;
use App\Game\Messages\MessageMailbox;
use App\Tests\Support\GameTestCase;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Queries whose SQL is assembled at runtime and now run through the DBAL QueryBuilder (Database::qb()): each family is
 * compared with the plain SQL it replaced (the old statement is kept here verbatim as the oracle), including ordering of
 * ties and paging, so a changed result shows up as a failing test.
 */
final class QueryBuilderQueriesTest extends GameTestCase
{
    public function testTablePlaceholderAndExecuteBookkeeping(): void
    {
        $this->assertSame($this->db->getTablePrefix() . 'users', $this->db->table('USERS'));
        $this->assertSame($this->db->getTablePrefix() . 'users_to_topkb', $this->db->table('TOPKB_USERS'));
        try {
            $this->db->table('NO_SUCH_TABLE');
            $this->fail('unknown table names must throw');
        } catch (\Exception $e) {
            $this->assertStringContainsString('NO_SUCH_TABLE', $e->getMessage());
        }

        [$user] = $this->createPlayer();
        [$other] = $this->createPlayer();
        $before = $this->db->getQueryCounter();
        $rows = $this->db->fetchAll(
            $this->db->qb()->select('id', 'username')->from($this->db->table('USERS'))
                ->where('id IN (:ids)')->setParameter('ids', [(int) $user['id'], (int) $other['id']], ArrayParameterType::INTEGER)
                ->orderBy('id', 'DESC'),
        );
        $this->assertSame([(int) $other['id'], (int) $user['id']], array_map(static fn(array $r): int => (int) $r['id'], $rows));
        $this->assertSame($before + 1, $this->db->getQueryCounter());

        $affected = $this->db->execute(
            $this->db->qb()->update($this->db->table('USERS'))->set('`username`', ':name')->where('id = :id')
                ->setParameter('name', 'renamed')->setParameter('id', $user['id']),
        );
        $this->assertSame(1, $affected);
        $this->assertSame(1, $this->db->rowCount());
        $this->assertFalse($this->db->fetchRow($this->db->qb()->select('id')->from($this->db->table('USERS'))->where('id = -1')));
        $this->assertSame((string) $user['id'], (string) $this->db->fetchValue($this->db->qb()->select('id')->from($this->db->table('USERS'))->where('id = :id')->setParameter('id', $user['id'])));
    }

    /** @return list<array<string, mixed>> */
    private function sixPlayers(): array
    {
        $players = [];
        foreach ([['name' => 'zed', 'online' => 100], ['name' => 'amy', 'online' => 100], ['name' => 'bob', 'online' => 300], ['name' => 'cat', 'online' => 200], ['name' => 'dan', 'online' => 200], ['name' => 'eve', 'online' => 50]] as $i => $spec) {
            [$user, $planet] = $this->createPlayer(['username' => $spec['name'] . 'qb', 'onlinetime' => $spec['online'], 'authlevel' => $i === 5 ? 1 : 0]);
            $players[] = $user + ['planet' => $planet];
        }

        return $players;
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    private function search(array $admin, string $table, string $file, string $searchIn, string $method, string $key, ?string $order, string $dir, int $limit, int $page): array
    {
        $this->state->USER = $admin;
        $this->state->universeEmulated = 1;
        $controller = (new SearchController())->withGameServices($this->db, $this->configs, $this->state);
        $items = match ($table) {
            'USERS' => ['id', 'username', 'onlinetime', 'register_time', 'user_lastip', 'authlevel', 'bana', 'urlaubs_modus'],
            'PLANETS' => ['p.id', 'p.name', "CONCAT(u.username, ' (ID:&nbsp;', p.id_owner, ')') AS owner", 'p.last_update', 'p.galaxy', 'p.`system`', 'p.planet', 'p.id_luna'],
        };
        $orderable = $table === 'USERS'
            ? ['id', 'username', 'onlinetime', 'register_time', 'user_lastip', 'authlevel', 'bana', 'urlaubs_modus']
            : ['id', 'name', 'id_owner', 'id_luna', 'last_update', 'galaxy', 'system', 'planet'];
        $nameColumn = $table === 'USERS' ? 'username' : 'p.name';
        $idColumn = $table === 'USERS' ? 'id' : 'p.id';
        $special = $table === 'USERS' ? [['universe', '=', 1]] : [['p.universe', '=', 1], ['p.planet_type', '=', 1]];
        [$operator, $value] = match ($method) {
            'exacto' => ['= :searchKey', $key],
            'last' => ['LIKE :searchKey', $key . '%'],
            'first' => ['LIKE :searchKey', '%' . $key],
            default => ['LIKE :searchKey', '%' . $key . '%'],
        };

        return $controller->MyCrazyLittleSearch(
            $items,
            $searchIn === 'name' ? $nameColumn : $idColumn,
            $operator,
            $value,
            $special,
            $order,
            $dir,
            $limit,
            $table,
            $page,
            array_fill(0, count($items), 'col'),
            $orderable,
            'name',
            $file,
        );
    }

    public function testAdminUserSearchMatchesPlainSqlForOrderingAndPaging(): void
    {
        $players = $this->sixPlayers();
        $admin = $players[0] + ['authlevel' => AUTH_ADM];
        $admin['timezone'] ??= 'UTC';
        $oracle = static fn($db, string $order, string $dir, int $offset, int $limit) => array_map(
            static fn(array $row): int => (int) $row['id'],
            $db->select("SELECT id,username,onlinetime,register_time,user_lastip,authlevel,bana,urlaubs_modus FROM %%USERS%% WHERE username LIKE :searchKey AND universe = '1' ORDER BY $order $dir LIMIT $offset,$limit", [':searchKey' => '%qb%']),
        );

        foreach (['username', 'onlinetime', 'authlevel', 'id'] as $order) {
            foreach (['ASC', 'DESC'] as $dir) {
                foreach ([1, 2, 3] as $page) {
                    $result = $this->search($admin, 'USERS', 'users', 'name', 'normal', 'qb', $order, $dir, 2, $page);
                    $ids = array_map(static fn(array $row): int => (int) $row['cells'][0], $result['rows']);
                    $this->assertSame($oracle($this->db, $order, $dir, ($page - 1) * 2, 2), $ids, "$order $dir page $page");
                    $this->assertSame(6, $result['total']);
                    $this->assertSame(3, $result['pages']);
                }
            }
        }

        // unknown sort column falls back to the first column; unknown direction to ASC
        $result = $this->search($admin, 'USERS', 'users', 'name', 'normal', 'qb', 'password', 'sideways', 10, 1);
        $this->assertSame($oracle($this->db, 'id', 'ASC', 0, 10), array_map(static fn(array $row): int => (int) $row['cells'][0], $result['rows']));

        // exact / prefix / suffix matching and the id search
        $this->assertSame(1, $this->search($admin, 'USERS', 'users', 'name', 'exacto', 'zedqb', 'id', 'ASC', 10, 1)['total']);
        $this->assertSame(1, $this->search($admin, 'USERS', 'users', 'name', 'last', 'zed', 'id', 'ASC', 10, 1)['total']);
        $this->assertSame(1, $this->search($admin, 'USERS', 'users', 'name', 'first', 'amyqb', 'id', 'ASC', 10, 1)['total']);
        $this->assertSame(1, $this->search($admin, 'USERS', 'users', 'id', 'exacto', (string) $players[2]['id'], 'id', 'ASC', 10, 1)['total']);
        $this->assertTrue($this->search($admin, 'USERS', 'users', 'name', 'exacto', 'nobody', 'id', 'ASC', 10, 1)['empty']);
    }

    public function testAdminPlanetSearchJoinsTheOwnerAndSortsBySystem(): void
    {
        $players = $this->sixPlayers();
        $admin = $players[0] + ['authlevel' => AUTH_ADM];
        $admin['timezone'] ??= 'UTC';
        $oracle = $this->db->select(
            "SELECT p.id,p.name,CONCAT(u.username, ' (ID:&nbsp;', p.id_owner, ')') as owner,p.last_update,p.galaxy,p.system,p.planet,p.id_luna FROM %%PLANETS%% p LEFT JOIN %%USERS%% u ON u.id = p.id_owner WHERE p.name LIKE :searchKey AND planet_type = '1' AND p.universe = 1 ORDER BY system DESC LIMIT 0,4",
            [':searchKey' => '%%'],
        );
        $result = $this->search($admin, 'PLANETS', 'planet', 'name', 'normal', '', 'system', 'DESC', 4, 1);
        $this->assertSame(array_map(static fn(array $r): int => (int) $r['id'], $oracle), array_map(static fn(array $r): int => (int) $r['cells'][0], $result['rows']));
        $this->assertSame(array_map(static fn(array $r) => $r['owner'], $oracle), array_map(static fn(array $r) => $r['cells'][2], $result['rows']));
    }

    /** @param array<string, mixed> $filter */
    private function topKbOracle(array $filter, array $user): array
    {
        $memorial = [BattleHallFilter::MEMORIAL_EXCLUDE => ' AND %%TOPKB%%.memorial = 0 ', BattleHallFilter::MEMORIAL_ONLY => ' AND %%TOPKB%%.memorial = 1 '][$filter['memorial']] ?? '';
        $time = '';
        if ($filter['timeframe']) {
            $span = [1 => TIME_24_HOURS, 2 => TIME_1_WEEK, 3 => TIME_1_MONTH][$filter['timeframe']] + TIME_6_HOURS;
            $time = " AND time > UNIX_TIMESTAMP() - $span ";
        }
        $location = $filter['galaxy'] ? ' AND %%TOPKB%%.galaxy = ' . (int) $filter['galaxy'] : '';
        $diplomacy = '';
        if ($filter['diplomacy'] == BattleHallFilter::DIPLOMACY_SELF) {
            $diplomacy = ' AND %%TOPKB%%.rid IN (SELECT DISTINCT %%TOPKB%%.rid FROM %%TOPKB%% JOIN %%TOPKB_USERS%% ON %%TOPKB%%.rid = %%TOPKB_USERS%%.rid WHERE %%TOPKB_USERS%%.uid =' . (int) $user['id'] . ')';
        } elseif ($filter['diplomacy'] == BattleHallFilter::DIPLOMACY_ALLIANCE && (int) $user['ally_id'] > 0) {
            $diplomacy = ' AND %%TOPKB%%.rid IN (SELECT DISTINCT %%TOPKB%%.rid FROM %%TOPKB%% JOIN %%TOPKB_USERS%% ON %%TOPKB%%.rid = %%TOPKB_USERS%%.rid WHERE %%TOPKB_USERS%%.uid IN (SELECT `id` FROM %%USERS%% WHERE `ally_id` = ' . (int) $user['ally_id'] . '))';
        }

        return $this->db->select("SELECT *, (
			SELECT DISTINCT
			IF(%%TOPKB_USERS%%.username = '', GROUP_CONCAT(%%USERS%%.username SEPARATOR ' & '), GROUP_CONCAT(%%TOPKB_USERS%%.username SEPARATOR ' & '))
			FROM %%TOPKB_USERS%%
			LEFT JOIN %%USERS%% ON uid = %%USERS%%.id
			WHERE %%TOPKB_USERS%%.rid = %%TOPKB%%.rid AND role = 1
		) as attacker,
		(
			SELECT DISTINCT
			IF(%%TOPKB_USERS%%.username = '', GROUP_CONCAT(%%USERS%%.username SEPARATOR ' & '), GROUP_CONCAT(%%TOPKB_USERS%%.username SEPARATOR ' & '))
			FROM %%TOPKB_USERS%% INNER JOIN %%USERS%% ON uid = id
			WHERE %%TOPKB_USERS%%.rid = %%TOPKB%%.`rid` AND `role` = 2
		) as defender
		FROM %%TOPKB%%
		 WHERE universe = :universe AND time < UNIX_TIMESTAMP() - 21600
			$memorial $time $location $diplomacy
		 ORDER BY %%TOPKB%%.units DESC, %%TOPKB%%.time ASC LIMIT 100;", [':universe' => 1]);
    }

    public function testBattleHallFilterMatchesPlainSqlForEveryFilterCombination(): void
    {
        [$me] = $this->createPlayer();
        [$friend] = $this->createPlayer();
        [$stranger] = $this->createPlayer();
        $this->db->update('UPDATE %%USERS%% SET ally_id = 77 WHERE id IN (:a, :b);', [':a' => $me['id'], ':b' => $friend['id']]);
        $me['ally_id'] = 77;
        $now = time();
        // equal units on purpose: the tie is broken by the older report first
        $reports = [
            ['r1', 5000, $now - 90000, 1, 0, $me, $stranger],
            ['r2', 5000, $now - 80000, 2, 1, $friend, $stranger],
            ['r3', 9000, $now - 700000, 1, 0, $stranger, $me],
            ['r4', 100, $now - 3000000, 3, 1, $stranger, $stranger],
            ['r5', 7000, $now - 10000, 1, 0, $me, $friend],
            ['r6', 5000, $now - 85000, 1, 0, $friend, $friend],
        ];
        foreach ($reports as [$rid, $units, $time, $galaxy, $memorial, $attacker, $defender]) {
            $this->db->insert('INSERT INTO %%TOPKB%% (rid, units, result, time, universe, galaxy, memorial) VALUES (:rid, :units, :result, :time, 1, :galaxy, :memorial);', [':rid' => $rid, ':units' => $units, ':result' => 'a', ':time' => $time, ':galaxy' => $galaxy, ':memorial' => $memorial]);
            $this->db->insert("INSERT INTO %%TOPKB_USERS%% (rid, uid, username, role) VALUES (:rid, :uid, '', 1);", [':rid' => $rid, ':uid' => $attacker['id']]);
            $this->db->insert("INSERT INTO %%TOPKB_USERS%% (rid, uid, username, role) VALUES (:rid, :uid, '', 2);", [':rid' => $rid, ':uid' => $defender['id']]);
        }
        $this->state->USER = $me;
        $this->state->universeCurrent = 1;

        $combinations = 0;
        foreach ([0, 1, 2] as $memorial) {
            foreach ([0, 1, 2, 3] as $timeframe) {
                foreach ([0, 1, 2] as $diplomacy) {
                    foreach ([0, 1, 2] as $galaxy) {
                        $filter = ['memorial' => $memorial, 'timeframe' => $timeframe, 'diplomacy' => $diplomacy, 'galaxy' => $galaxy];
                        $expected = $this->topKbOracle($filter, $me);
                        $actual = (new BattleHallFilter($this->db, $this->configs, $this->state, $filter))->getTopKBs();
                        $this->assertSame($expected, $actual, json_encode($filter));
                        ++$combinations;
                    }
                }
            }
        }
        $this->assertSame(108, $combinations);
        $all = (new BattleHallFilter($this->db, $this->configs, $this->state, null))->getTopKBs();
        $this->assertSame(['r3', 'r1', 'r6', 'r2', 'r4'], array_column($all, 'rid'), 'units descending, equal units: older first, reports inside the 6 hour delay (r5) hidden');
    }

    public function testPlanetListFollowsTheSortSettings(): void
    {
        [$user] = $this->createPlayer();
        $players = new PlayerUtil($this->db, $this->configs, $this->state);
        foreach ([[1, 5, 9, 'mmm'], [1, 5, 3, 'aaa'], [1, 2, 7, 'zzz'], [2, 9, 1, 'ccc']] as [$galaxy, $system, $planet, $name]) {
            $players->createPlanet($galaxy, $system, $planet, 1, (int) $user['id'], $name);
        }
        $queries = new PlanetQueries($this->db, $this->configs, $this->state);
        $orders = [
            0 => 'id %s',
            1 => 'galaxy %1$s, system %1$s, planet %1$s, planet_type %1$s',
            2 => 'name %s',
        ];
        foreach ($orders as $sort => $pattern) {
            foreach ([0 => 'ASC', 1 => 'DESC'] as $flag => $dir) {
                $user['planet_sort'] = $sort;
                $user['planet_sort_order'] = $flag;
                unset($user['PLANETS']);
                $expected = array_column($this->db->select('SELECT id FROM %%PLANETS%% WHERE id_owner = :u AND destruyed = 0 ORDER BY ' . sprintf($pattern, $dir), [':u' => $user['id']]), 'id');
                $this->assertSame(array_map('intval', $expected), array_map('intval', array_keys($queries->getPlanets($user))), "sort $sort $dir");
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return list<int> message ids of the user's mailbox, in creation order
     */
    private function mailbox(array $user, array $types): array
    {
        $ids = [];
        foreach ($types as $type) {
            $this->db->insert('INSERT INTO %%MESSAGES%% (message_owner, message_sender, message_time, message_type, message_from, message_subject, message_text, message_unread, message_universe) VALUES (:o, 0, :t, :type, :f, :s, :x, 1, 1);', [':o' => $user['id'], ':t' => time(), ':type' => $type, ':f' => 'sys', ':s' => 'subject', ':x' => 'text']);
            $messageId = $this->db->lastInsertId();
            $this->db->insert('INSERT INTO %%USERS_TO_MESSAGES%% (message_id, user_id, sender_id, forwarder_id, message_unread, message_favorite, message_forwarded, created_at) VALUES (:m, :u, NULL, NULL, 1, 0, 0, :t);', [':m' => $messageId, ':u' => $user['id'], ':t' => time()]);
            $ids[] = $messageId;
        }

        return $ids;
    }

    /** @return array<int, array{unread: int, deleted: bool}> */
    private function mailboxState(array $user): array
    {
        $state = [];
        foreach ($this->db->select('SELECT message_id, message_unread, deleted_at FROM %%USERS_TO_MESSAGES%% WHERE user_id = :u ORDER BY message_id;', [':u' => $user['id']]) as $row) {
            $state[(int) $row['message_id']] = ['unread' => (int) $row['message_unread'], 'deleted' => $row['deleted_at'] !== null];
        }

        return $state;
    }

    public function testMailboxSelectionActionsUseBoundIdLists(): void
    {
        [$user] = $this->createPlayer();
        [$other] = $this->createPlayer();
        $this->state->USER = $user;
        $mine = $this->mailbox($user, [MESSAGE_TYPE_USER, MESSAGE_TYPE_USER, MESSAGE_TYPE_SYSTEM, MESSAGE_TYPE_USER]);
        $theirs = $this->mailbox($other, [MESSAGE_TYPE_USER]);
        // message 3 (index 3) is a favourite: deletions skip it
        $this->db->update('UPDATE %%USERS_TO_MESSAGES%% SET message_favorite = 1 WHERE message_id = :m;', [':m' => $mine[3]]);
        $mailbox = new MessageMailbox($this->db, $this->configs, $this->state);

        $mailbox->applyAction('readmarked', MESSAGE_TYPE_ALL, [$mine[0] => 'on', $theirs[0] => 'on', 'x1' => 'on']);
        $this->assertSame([0, 1, 1, 1], array_column(array_values($this->mailboxState($user)), 'unread'));
        $this->assertSame(1, $this->mailboxState($other)[$theirs[0]]['unread'], 'foreign messages stay untouched');

        $mailbox->applyAction('deletemarked', MESSAGE_TYPE_ALL, [$mine[1] => 'on', $mine[3] => 'on']);
        $this->assertSame([false, true, false, false], array_column(array_values($this->mailboxState($user)), 'deleted'), 'favourites are not deleted');

        // delete everything of the category that is not selected: message 0 is selected, 3 is a favourite, 1 is already gone
        $mailbox->applyAction('deleteunmarked', MESSAGE_TYPE_USER, [$mine[0] => 'on']);
        $this->assertSame([false, true, false, false], array_column(array_values($this->mailboxState($user)), 'deleted'));
        // across all categories the system message goes as well
        $mailbox->applyAction('deleteunmarked', MESSAGE_TYPE_ALL, [$mine[0] => 'on']);
        $this->assertSame([false, true, true, false], array_column(array_values($this->mailboxState($user)), 'deleted'));
        $this->assertFalse($this->mailboxState($other)[$theirs[0]]['deleted']);
    }

    public function testUpdateRowsBindsValuesExpressionsAndIncrements(): void
    {
        [, $planet] = $this->createPlayer([], ['metal' => 1000, 'crystal' => 500, 'metal_mine' => 4, 'name' => 'Before', 'b_building_id' => 'x']);
        $id = (int) $planet['id'];

        $this->assertSame(0, $this->db->updateRows('PLANETS', [], ['id' => $id]), 'nothing to set runs no query');
        $before = $this->db->getQueryCounter();
        $this->assertSame(1, $this->db->updateRows('PLANETS', ['name' => "O'Brien; DROP x"], ['id' => $id], ['b_building_id' => "''"], ['metal' => -300, 'crystal' => '250', 'metal_mine' => 1]));
        $this->assertSame($before + 1, $this->db->getQueryCounter());

        $after = $this->loadPlanet($id);
        $this->assertSame("O'Brien; DROP x", $after['name']);
        $this->assertSame('', $after['b_building_id']);
        $this->assertSame(700, (int) $after['metal']);
        $this->assertSame(750, (int) $after['crystal']);
        $this->assertSame(5, (int) $after['metal_mine']);

        // a where on a value that does not match changes nothing
        $this->assertSame(0, $this->db->updateRows('PLANETS', ['name' => 'Other'], ['id' => $id, 'universe' => 99]));
        $this->assertSame("O'Brien; DROP x", $this->loadPlanet($id)['name']);

        // reserved words are quoted by the helper
        $this->assertSame(1, $this->db->updateRows('PLANETS', ['system' => 321], ['id' => $id]), 'a value different from the generated system (1..100)');
        $this->assertSame(321, (int) $this->loadPlanet($id)['system']);

        foreach ([['name` = 1, `metal' => 1], ['na me' => 1]] as $invalid) {
            try {
                $this->db->updateRows('PLANETS', $invalid, ['id' => $id]);
                $this->fail('column names must be plain identifiers');
            } catch (\Exception $e) {
                $this->assertSame('Invalid column name', $e->getMessage());
            }
        }
    }
}

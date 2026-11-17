<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\RightsController;
use App\Game\Admin\AccountEditor\AlliancesTab;
use App\Game\Admin\AccountEditor\BuildingsTab;
use App\Game\Admin\AccountEditor\DefensesTab;
use App\Game\Admin\AccountEditor\PersonalTab;
use App\Game\Admin\AccountEditor\PlanetsTab;
use App\Game\Admin\AccountEditor\ResearchTab;
use App\Game\Admin\AccountEditor\ResourcesTab;
use App\Game\Admin\AccountEditor\ShipsTab;
use App\Game\Admin\ClosureAdminResponder;
use App\Game\Core\StoredData;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use App\Game\Enum\AuthLevel;

/**
 * Every write of the account editor tabs and of the rights page leaves exactly one admin log row (with old and new
 * values), so staff actions stay traceable.
 */
final class AdminActionLogTest extends GameTestCase
{
    private function withBrowserSession(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::container()->get('request_stack')->push($request);
    }

    private function logCount(): int
    {
        return (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%LOG%%;', [], 'c');
    }

    /** @return array<string, mixed> the newest log row */
    private function lastLog(): array
    {
        return $this->db->selectSingle('SELECT * FROM %%LOG%% ORDER BY id DESC LIMIT 1;');
    }

    /** @param array<string, mixed> $post */
    private function runTab(string $tab, array $post): void
    {
        $this->state->universeEmulated = 1;
        $this->state->requestPost = $post;
        $this->state->requestInput = $post;
        $responder = new ClosureAdminResponder(
            static function ($message): never {
                throw new \RuntimeException((string) $message);
            },
            static function (array $variables): void {},
            static fn(string $template): Response => new Response($template),
        );
        try {
            (new $tab($this->db, $this->configs, $this->state, $responder))->handle();
        } catch (\RuntimeException) {
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed> the log row the action wrote
     */
    private function assertOneLog(string $tab, array $post, int $mode, int $target): array
    {
        $before = $this->logCount();
        $this->runTab($tab, $post);
        $this->assertSame($before + 1, $this->logCount(), $tab . ' writes exactly one log row');
        $row = $this->lastLog();
        $this->assertSame($mode, (int) $row['mode']);
        $this->assertSame($target, (int) $row['target']);

        return $row;
    }

    public function testAccountEditorTabsLogOneRowPerAction(): void
    {
        [$admin] = $this->createPlayer(['authlevel' => AuthLevel::Admin->value]);
        [$user, $planet] = $this->createPlayer();
        $this->state->USER = $admin;
        $pid = (int) $planet['id'];
        $uid = (int) $user['id'];

        $this->assertOneLog(ResourcesTab::class, ['id' => $pid, 'metal' => 10, 'add' => 1], 2, $pid);
        $this->assertOneLog(ResourcesTab::class, ['id' => $pid, 'metal' => 5, 'delete' => 1], 2, $pid);
        $this->assertOneLog(ShipsTab::class, ['id' => $pid, 'light_hunter' => 3, 'add' => 1], 2, $pid);
        $this->assertOneLog(DefensesTab::class, ['id' => $pid, 'misil_launcher' => 3, 'add' => 1], 2, $pid);
        $this->assertOneLog(BuildingsTab::class, ['id' => $pid, 'metal_mine' => 2, 'add' => 1], 2, $pid);
        $this->assertOneLog(ResearchTab::class, ['id' => $uid, 'spy_tech' => 2, 'add' => 1], 1, $uid);
        $this->assertOneLog(PersonalTab::class, ['id' => $uid, 'username' => 'Renamed' . $uid], 1, $uid);
        $row = $this->assertOneLog(PlanetsTab::class, ['id' => $pid, 'name' => 'Logged'], 2, $pid);
        $data = StoredData::tryDecode($row['data']);
        $this->assertSame('Logged', $data[1]['name']);
        $this->assertNotSame('Logged', $data[0]['name']);
    }

    public function testAllianceEditsAreLogged(): void
    {
        [$admin] = $this->createPlayer(['authlevel' => AuthLevel::Admin->value]);
        [$member] = $this->createPlayer();
        $this->state->USER = $admin;
        $this->db->insert("INSERT INTO %%ALLIANCE%% (ally_name, ally_tag, ally_owner, ally_register_time, ally_universe, ally_events, ally_members) VALUES ('LogAl', 'LGA', :o, :t, 1, '', 1);", [':o' => $member['id'], ':t' => time()]);
        $allyId = (int) $this->db->lastInsertId();

        $row = $this->assertOneLog(AlliancesTab::class, ['id' => $allyId, 'name' => 'LogAl2'], 6, $allyId);
        $this->assertSame('LogAl2', StoredData::tryDecode($row['data'])[1]['ally_name']);
        $this->assertOneLog(AlliancesTab::class, ['id' => $allyId, 'delete' => 'on'], 6, $allyId);
    }

    public function testAuthlevelAndRightsChangesAreLogged(): void
    {
        [$admin] = $this->createPlayer(['authlevel' => AuthLevel::Admin->value]);
        [$user] = $this->createPlayer();
        $uid = (int) $user['id'];
        $this->state->USER = $admin;
        $this->state->universeEmulated = 1;
        $controller = (new RightsController())->withGameServices($this->db, $this->configs, $this->state);
        $controller->setRouter(self::container()->get('router'));

        $run = function (array $post, array $query, string $action) use ($controller): void {
            $this->state->requestPost = $post;
            $this->state->requestQuery = $query;
            $this->state->requestInput = $post + $query;
            $this->withBrowserSession();
            try {
                $controller->{$action}();
            } catch (\Throwable) {
            }
        };

        $before = $this->logCount();
        $run(['id_1' => $uid, 'authlevel' => AuthLevel::Moderator->value], ['page' => 'rights', 'mode' => 'users'], 'users');
        $this->assertSame($before + 1, $this->logCount());
        $this->assertSame(AuthLevel::Moderator->value, (int) $this->loadUser($uid)['authlevel']);
        $row = $this->lastLog();
        $this->assertSame([[ 'authlevel' => AuthLevel::User->value], ['authlevel' => AuthLevel::Moderator->value]], StoredData::tryDecode($row['data']));
        $this->assertSame($uid, (int) $row['target']);

        $before = $this->logCount();
        $run(['id_1' => $uid, 'action' => 'send', 'rights' => ['ShowLogPage.php' => 1]], ['page' => 'rights', 'mode' => 'rights'], 'show');
        $this->assertSame($before + 1, $this->logCount());
        $this->assertSame(['ShowLogPage.php' => 1], StoredData::tryDecode($this->lastLog()['data'])[1]);
    }
}

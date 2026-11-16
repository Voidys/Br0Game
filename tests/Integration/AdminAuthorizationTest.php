<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\AccountswapController;
use App\Controller\Admin\RightsController;
use App\Controller\Admin\SearchController;
use App\Game\Admin\ClosureAdminResponder;
use App\Game\Admin\Creator\AccountCreator;
use App\Game\Bots\Llm\LlmClientFactory;
use App\Game\Core\EndOfRequest;
use App\Game\Core\SQLDumper;
use App\Game\Cronjob\Cronjob;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Server-side limits of the admin tools: what the menu hides must also be refused when the request is built by hand
 * (a moderator with a page right, GET instead of POST, ids of higher-ranked players, table names with options).
 */
final class AdminAuthorizationTest extends GameTestCase
{
    private function asUser(array $user, array $post = [], array $query = [], string $method = 'POST'): void
    {
        $this->state->USER = $user;
        $this->state->universeEmulated = 1;
        $this->state->requestPost = $method === 'POST' ? $post : [];
        $this->state->requestQuery = $query;
        $this->state->requestInput = $post + $query;
        $this->state->requestServer = ['REQUEST_METHOD' => $method] + ($this->state->requestServer ?? []);
    }

    private function show(object $controller): ?string
    {
        try {
            $controller->show();
        } catch (EndOfRequest) {
            return 'ended';
        } catch (\Throwable $e) {
            // the notice page needs a browser session, which a test does not have: the outcome is checked in the database
            return $e->getMessage();
        }

        return null;
    }

    private function exists(int $userId): bool
    {
        return !empty($this->db->selectSingle('SELECT id FROM %%USERS%% WHERE id = :id;', [':id' => $userId]));
    }

    public function testModeratorCannotSwapAccountsWithAnAdmin(): void
    {
        [$mod] = $this->createPlayer(['authlevel' => AUTH_MOD]);
        [$admin] = $this->createPlayer(['authlevel' => AUTH_ADM]);
        $before = $this->db->selectSingle('SELECT account_id FROM %%ACCOUNTS_TO_USERS%% WHERE user_id = :u;', [':u' => $admin['id']], 'account_id');

        $this->asUser($mod, ['action' => 'swap', 'user1' => $mod['id'], 'user2' => $admin['id']]);
        $this->show((new AccountswapController())->withGameServices($this->db, $this->configs, $this->state));

        $after = $this->db->selectSingle('SELECT account_id FROM %%ACCOUNTS_TO_USERS%% WHERE user_id = :u;', [':u' => $admin['id']], 'account_id');
        $this->assertSame($before, $after);
    }

    public function testModeratorCannotDeleteViaSearchAndGetIsRefused(): void
    {
        [$mod] = $this->createPlayer(['authlevel' => AUTH_MOD]);
        [$victim] = $this->createPlayer();
        [$admin] = $this->createPlayer(['authlevel' => AUTH_ADM]);
        [$otherAdmin] = $this->createPlayer(['authlevel' => AUTH_ADM]);

        $this->asUser($mod, [], ['page' => 'search', 'delete' => 'user', 'user' => $victim['id']]);
        $this->show((new SearchController())->withGameServices($this->db, $this->configs, $this->state));
        $this->assertTrue($this->exists((int) $victim['id']), 'moderator must not delete');

        $this->asUser($admin, [], ['page' => 'search', 'delete' => 'user', 'user' => $victim['id']], 'GET');
        $this->show((new SearchController())->withGameServices($this->db, $this->configs, $this->state));
        $this->assertTrue($this->exists((int) $victim['id']), 'GET must not delete');

        $this->asUser($admin, [], ['page' => 'search', 'delete' => 'user', 'user' => $otherAdmin['id']]);
        $this->show((new SearchController())->withGameServices($this->db, $this->configs, $this->state));
        $this->assertTrue($this->exists((int) $otherAdmin['id']), 'an admin must not delete an equal level');

        $this->asUser($admin, [], ['page' => 'search', 'delete' => 'user', 'user' => ROOT_USER]);
        $this->show((new SearchController())->withGameServices($this->db, $this->configs, $this->state));

        $this->asUser($admin, [], ['page' => 'search', 'delete' => 'user', 'user' => $victim['id']]);
        $this->show((new SearchController())->withGameServices($this->db, $this->configs, $this->state));
        $this->assertFalse($this->exists((int) $victim['id']), 'admin POST deletes a lower player');
    }

    public function testRightsPageIsAdminOnly(): void
    {
        [$mod] = $this->createPlayer(['authlevel' => AUTH_OPS, 'rights' => '{"ShowRightsPage":1}']);
        $this->asUser($mod);
        $controller = (new RightsController())->withGameServices($this->db, $this->configs, $this->state);

        $this->withBrowserSession();
        try {
            (function (): void {
                $this->construct();
            })->call($controller);
            $this->fail('the rights page must refuse an operator');
        } catch (EndOfRequest $end) {
            $this->assertSame(403, $end->getResponse()?->getStatusCode(), 'a refusal is the permission page (403), not the 503 error page');
        }
    }

    public function testRefusedActionAnswersWith403Page(): void
    {
        [$mod] = $this->createPlayer(['authlevel' => AUTH_MOD]);
        [$victim] = $this->createPlayer();
        $this->withBrowserSession();
        $this->asUser($mod, [], ['page' => 'search', 'delete' => 'user', 'user' => $victim['id']]);

        try {
            (new SearchController())->withGameServices($this->db, $this->configs, $this->state)->show();
            $this->fail('expected a refusal');
        } catch (EndOfRequest $end) {
            $this->assertSame(403, $end->getResponse()?->getStatusCode());
        }
    }

    private function withBrowserSession(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::container()->get('request_stack')->push($request);
    }

    public function testCreatorNeverGivesAHigherLevelThanTheCreators(): void
    {
        [$mod] = $this->createPlayer(['authlevel' => AUTH_MOD]);
        $this->asUser($mod, ['name' => 'evilnew', 'password' => 'secret12', 'password2' => 'secret12', 'email' => 'evilnew@example.com', 'email2' => 'evilnew@example.com', 'authlevel' => AUTH_ADM, 'lang' => 'en']);
        $this->state->LNG ??= null;
        $responder = new ClosureAdminResponder(
            static function (): never {
                throw new EndOfRequest();
            },
            static function (array $variables): void {},
            static fn(string $template): Response => new Response($template),
        );
        try {
            (new AccountCreator($this->db, $this->configs, $this->state, $responder))->handle();
        } catch (EndOfRequest) {
        }

        $level = $this->db->selectSingle('SELECT authlevel FROM %%ACCOUNTS%% WHERE accountname = :n;', [':n' => 'evilnew'], 'authlevel');
        $this->assertSame(AUTH_MOD, (int) $level);
    }

    public function testDumpRefusesUnknownTablesAndOptions(): void
    {
        $dumper = new SQLDumper($this->db, $this->configs, $this->state);
        $file = sys_get_temp_dir() . '/dump-auth-test.sql';

        foreach ([['--host=attacker.example'], ['--result-file=/tmp/x'], ['mysql.user'], ['uni1_users', 'other_table'], []] as $tables) {
            try {
                $dumper->dumpTablesToFile($tables, $file);
                $this->fail('expected InvalidArgumentException for ' . json_encode($tables));
            } catch (\InvalidArgumentException) {
                $this->assertFileDoesNotExist($file);
            }
        }
    }

    public function testDumpOfTwoRealTablesWorksNatively(): void
    {
        $file = sys_get_temp_dir() . '/dump-two-tables.sql';
        @unlink($file);

        (new SQLDumper($this->db, $this->configs, $this->state))->dumpTablesToFile(['uni1_system', 'uni1_config'], $file);

        $sql = (string) file_get_contents($file);
        unlink($file);
        $this->assertStringContainsString('CREATE TABLE `uni1_system`', $sql);
        $this->assertStringContainsString('CREATE TABLE `uni1_config`', $sql);
        $this->assertStringContainsString('INSERT INTO `uni1_config`', $sql);
        $this->assertStringNotContainsString('attacker', $sql);
    }

    public function testChangingTheBaseUrlDropsTheStoredKey(): void
    {
        $this->db->delete("DELETE FROM %%BOT_LLM_PROVIDERS%% WHERE provider = 'openai';");
        $this->db->insert("INSERT INTO %%BOT_LLM_PROVIDERS%% (provider, api_key, model, base_url, is_active) VALUES ('openai', 'sk-secret', 'm', '', 1);");
        $factory = new LlmClientFactory($this->db, $this->state, new MockHttpClient());

        $factory->saveProvider('openai', null, 'm', '', true);
        $this->assertSame('sk-secret', $this->db->selectSingle("SELECT api_key FROM %%BOT_LLM_PROVIDERS%% WHERE provider = 'openai';", [], 'api_key'), 'unchanged URL keeps the key');

        $factory->saveProvider('openai', null, 'm', 'https://attacker.example/v1', true);
        $row = $this->db->selectSingle("SELECT api_key, is_active FROM %%BOT_LLM_PROVIDERS%% WHERE provider = 'openai';");
        $this->assertSame('', $row['api_key']);
        $this->assertSame(0, (int) $row['is_active']);

        $factory->saveProvider('openai', 'sk-new', 'm', 'https://other.example/v1', true);
        $this->assertSame('sk-new', $this->db->selectSingle("SELECT api_key FROM %%BOT_LLM_PROVIDERS%% WHERE provider = 'openai';", [], 'api_key'), 'a re-entered key is stored');
    }

    public function testCronJobRunsOnceAndIsAlwaysUnlocked(): void
    {
        $this->db->insert("INSERT INTO %%CRONJOBS%% (name, isActive, min, hours, dom, month, dow, class, nextTime, `lock`) VALUES ('authtest', 1, '*', '*', '*', '*', '*', 'NoSuchCronjob', :next, NULL);", [':next' => time() + 3600]);
        $id = (int) $this->db->lastInsertId();
        $cron = new Cronjob($this->db, $this->configs, $this->state);

        $cron->execute($id); // not due (stale list of another runner): skipped, no lock left
        $this->assertNull($this->db->selectSingle('SELECT `lock` FROM %%CRONJOBS%% WHERE cronjobID = :id;', [':id' => $id], 'lock'));

        $cron->execute($id, true); // the class does not exist: an \Error inside the job must not leave the lock behind
        $this->assertNull($this->db->selectSingle('SELECT `lock` FROM %%CRONJOBS%% WHERE cronjobID = :id;', [':id' => $id], 'lock'));
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%CRONJOBS_LOG%% WHERE cronjobID = :id;', [':id' => $id], 'c'));
    }
}

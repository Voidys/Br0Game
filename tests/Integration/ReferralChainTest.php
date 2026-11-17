<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Account\RegisterController as AccountRegister;
use App\Controller\Login\RegisterController as LoginRegister;
use App\Controller\Login\VertifyController;
use App\Game\Core\EndOfRequest;
use App\Game\Cronjob\ReferralCronjob;
use App\Runtime\CanonicalUrl;
use App\Security\AuthRateLimits;
use App\Tests\Support\GameTestCase;

/**
 * Referral chain: registration form -> pending account (ACCOUNTS_VALID.referralID) -> verification (ACCOUNTS.referralID)
 * -> first player (users.ref_id / ref_bonus) -> ReferralCronjob once the recruit has enough points.
 */
final class ReferralChainTest extends GameTestCase
{
    private mixed $previousRequest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $this->state->request ?? null;
        $this->setUniverseConfig(['ref_active' => 1, 'ref_minpoints' => 100]);
        $this->configs->get(ROOT_UNI)->ref_active = 1;
        $this->configs->get(ROOT_UNI)->ref_minpoints = 100;
        $this->setUniverseConfig(['mail_active' => 0, 'user_valid' => 0]);
        // the welcome message of a new player is sent by user id 1, which the empty test database does not have
        $this->db->nativeQuery('SET foreign_key_checks = 0');
    }

    protected function tearDown(): void
    {
        $this->db->nativeQuery('SET foreign_key_checks = 1');
        $this->state->request = $this->previousRequest;
        parent::tearDown();
    }

    /** @param object $controller */
    private function wire(object $controller): object
    {
        $container = self::container();

        $controller->withGameServices($this->db, $this->configs, $this->state);
        // a fresh client IP per call: the registration rate limit is stored outside the test transaction
        $this->state->request = \Symfony\Component\HttpFoundation\Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254)]);
        $controller->setSecurityServices($container->get(AuthRateLimits::class), $container->get(CanonicalUrl::class));
        $controller->setRouter($container->get('router'));

        if ($controller instanceof LoginRegister) {
            $controller->setBotProtection($container->get(\App\Security\BotProtection::class));
            $controller->initialize();
        }

        return $controller;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<mixed> $account
     */
    private function call(object $controller, string $method, array $input, array $account = []): void
    {
        $this->state->requestPost = $input;
        $this->state->requestInput = $input;
        $this->state->requestQuery = $input;
        if ($account !== []) {
            $this->state->ACCOUNT = $account;
        }
        ob_start();
        try {
            $controller->$method();
        } catch (EndOfRequest) {
        } catch (\LogicException $e) {
            // the Account step ends by opening the game session, which needs a web request; everything before it was done
            $this->assertStringContainsString('No session available', $e->getMessage());
        } finally {
            ob_end_clean();
        }
    }

    /**
     * @return array<mixed>
     */
    private function accountRow(int $id): array
    {
        return $this->db->selectSingle('SELECT * FROM %%ACCOUNTS%% WHERE id = :id;', [':id' => $id]);
    }

    /**
     * a verified account without any player, carrying $referral
     *
     * @return array<mixed>
     */
    private function freshAccount(int $referral): array
    {
        $name = 'refacc' . random_int(1000, 999999);
        $id = (new \App\Game\Core\AccountUtil($this->db, $this->configs, $this->state))
            ->createAccount($name, password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), $name . '@example.com', 'en');
        $this->db->update('UPDATE %%ACCOUNTS%% SET referralID = :r WHERE id = :id;', [':r' => $referral, ':id' => $id]);

        return $this->accountRow($id);
    }

    /** a valid bot-check solution (the form field `pow`), solved by brute force like the browser does */
    private function botCheckSolution(): string
    {
        $challenge = self::container()->get(\App\Security\ProofOfWork::class)->issue(500, 0, time() - 10);
        for ($n = 0; hash('sha256', $challenge['salt'] . $n) !== $challenge['challenge']; $n++) {
        }

        return base64_encode((string) json_encode(['salt' => $challenge['salt'], 'challenge' => $challenge['challenge'], 'signature' => $challenge['signature'], 'number' => $n]));
    }

    /**
     * @param array<mixed> $account
     * @return array<mixed>
     */
    private function registerPlayer(array $account, string $username): ?array
    {
        $controller = $this->wire(new AccountRegister());
        $this->call($controller, 'send', ['universe' => 1, 'username' => $username, 'language' => 'en', 'timezone' => 'Europe/Berlin'], $account);

        return $this->db->selectSingle('SELECT * FROM %%USERS%% WHERE username = :n;', [':n' => $username]) ?: null;
    }

    public function testRegistrationStoresReferralOnThePendingAccount(): void
    {
        [$recruiter] = $this->createPlayer();
        $this->state->ACCOUNT = ['id' => 0, 'authlevel' => 0];
        $this->call($this->wire(new LoginRegister()), 'send', [
            'username' => 'refpend', 'password' => 'secret12', 'passwordReplay' => 'secret12',
            'email' => 'refpend@example.com', 'emailReplay' => 'refpend@example.com',
            'rules' => 1, 'privacy' => 1, 'lang' => 'en', 'time' => 'Europe/Berlin', 'referralID' => $recruiter['id'],
            'pow' => $this->botCheckSolution(), 'website' => '',
        ]);

        $pending = $this->db->selectSingle('SELECT * FROM %%ACCOUNTS_VALID%% WHERE accountname = :n;', [':n' => 'refpend']);
        $this->assertSame((int) $recruiter['id'], (int) $pending['referralID']);
    }

    public function testVerificationMovesTheReferralToTheAccount(): void
    {
        [$recruiter] = $this->createPlayer();
        $this->db->insert(
            "INSERT INTO %%ACCOUNTS_VALID%% SET validationKey = 'k1', accountname = 'refver', password = :p, email = 'refver@example.com', date = :t, ip = '127.0.0.1', language = 'en', timezone = 'Europe/Berlin', referralID = :r;",
            [':p' => password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), ':t' => time(), ':r' => $recruiter['id']],
        );
        $validationId = $this->db->lastInsertId();

        $this->call($this->wire(new VertifyController()), 'json', ['i' => $validationId, 'k' => 'k1']);

        $account = $this->db->selectSingle('SELECT * FROM %%ACCOUNTS%% WHERE accountname = :n;', [':n' => 'refver']);
        $this->assertSame((int) $recruiter['id'], (int) $account['referralID']);
    }

    public function testFirstPlayerGetsTheReferralAndTheAccountValueIsCleared(): void
    {
        [$recruiter] = $this->createPlayer();
        $account = $this->freshAccount((int) $recruiter['id']);

        $user = $this->registerPlayer($account, 'refplayer1');

        $this->assertNotNull($user);
        $this->assertSame((int) $recruiter['id'], (int) $user['ref_id']);
        $this->assertSame(1, (int) $user['ref_bonus']);
        $this->assertSame(0, (int) $this->accountRow((int) $account['id'])['referralID']);
    }

    public function testReferrerFromAnotherUniverseOrMissingIsIgnored(): void
    {
        [$foreign] = $this->createPlayer();
        $this->db->update('UPDATE %%USERS%% SET universe = 2 WHERE id = :id;', [':id' => $foreign['id']]);

        $user = $this->registerPlayer($this->freshAccount((int) $foreign['id']), 'refplayer2');
        $this->assertNotNull($user);
        $this->assertSame(0, (int) $user['ref_id']);
        $this->assertSame(0, (int) $user['ref_bonus']);

        $user = $this->registerPlayer($this->freshAccount(99999999), 'refplayer3');
        $this->assertNotNull($user);
        $this->assertSame(0, (int) $user['ref_id']);
        $this->assertSame(0, (int) $user['ref_bonus']);
    }

    public function testRecruiterAtTheReferralCapIsIgnored(): void
    {
        $this->setUniverseConfig(['ref_max_referals' => 1]);
        $this->configs->get(ROOT_UNI)->ref_max_referals = 1;
        [$recruiter] = $this->createPlayer();

        $first = $this->registerPlayer($this->freshAccount((int) $recruiter['id']), 'refcap1');
        $this->assertSame((int) $recruiter['id'], (int) $first['ref_id']);

        $second = $this->registerPlayer($this->freshAccount((int) $recruiter['id']), 'refcap2');
        $this->assertNotNull($second);
        $this->assertSame(0, (int) $second['ref_id']);
        $this->assertSame(0, (int) $second['ref_bonus']);
    }

    public function testCronjobFindsTheRecruitOncePointsAreReached(): void
    {
        [$recruiter] = $this->createPlayer();
        [$recruit] = $this->createPlayer();
        $this->db->update('UPDATE %%USERS%% SET ref_id = :r, ref_bonus = 1 WHERE id = :id;', [':r' => $recruiter['id'], ':id' => $recruit['id']]);

        $task = self::container()->get(ReferralCronjob::class);
        $this->db->insert('INSERT INTO %%STATPOINTS%% SET id_owner = :id, id_ally = 0, stat_type = 1, universe = 1, total_points = 50 ON DUPLICATE KEY UPDATE total_points = 50;', [':id' => $recruit['id']]);
        $task->run();
        $this->assertSame(1, (int) $this->loadUser((int) $recruit['id'])['ref_bonus'], 'below ref_minpoints nothing happens');

        $this->db->update('UPDATE %%STATPOINTS%% SET total_points = 500 WHERE id_owner = :id AND stat_type = 1;', [':id' => $recruit['id']]);
        $task->run();
        $this->assertSame(0, (int) $this->loadUser((int) $recruit['id'])['ref_bonus']);
    }
}

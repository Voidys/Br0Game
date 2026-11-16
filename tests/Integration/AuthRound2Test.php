<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Account\RegisterController as AccountRegister;
use App\Controller\Login\LostPasswordController;
use App\Controller\Login\VertifyController;
use App\Game\Core\EndOfRequest;
use App\Runtime\CanonicalUrl;
use App\Security\Account;
use App\Security\AccountProvider;
use App\Security\AuthRateLimits;
use App\Security\LoginFormAuthenticator;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;

/**
 * Round 2 of the security review, account side: old password hashes keep working and are rehashed, no login by id,
 * the verification link and the reset key work exactly once, a GET never uses up a reset key.
 */
final class AuthRound2Test extends GameTestCase
{
    private mixed $previousRequest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $this->state->request ?? null;
        $this->setUniverseConfig(['mail_active' => 0, 'user_valid' => 0]);
    }

    protected function tearDown(): void
    {
        $this->state->request = $this->previousRequest;
        parent::tearDown();
    }

    private function account(string $password): int
    {
        $name = 'auth' . random_int(1000, 999999);

        return (new \App\Game\Core\AccountUtil($this->db, $this->configs, $this->state))
            ->createAccount($name, password_hash($password, PASSWORD_BCRYPT, ['cost' => 13]), $name . '@example.com', 'en');
    }

    /** runs the authenticator's checks for e-mail + password and returns the loaded account */
    private function login(string $email, string $password): Account
    {
        $authenticator = self::container()->get(LoginFormAuthenticator::class);
        $request = Request::create('/', 'POST', ['email' => $email, 'password' => $password]);
        $passport = $authenticator->authenticate($request);
        $user = $passport->getBadge(UserBadge::class)->getUser();
        $passport->getBadge(CustomCredentials::class)->executeCustomChecker($user);

        return $user;
    }

    public function testRawPasswordLogsIn(): void
    {
        $id = $this->account('ab&cd"<e>f');
        $email = $this->db->selectSingle('SELECT email FROM %%ACCOUNTS%% WHERE id = :id;', [':id' => $id], 'email');

        $this->assertSame($id, $this->login($email, 'ab&cd"<e>f')->getId());
    }

    public function testOldEncodedHashStillLogsInAndIsRehashedWithTheRawPassword(): void
    {
        // what the former registration form stored for the typed password ab&cd"<e>f
        $id = $this->account('ab&amp;cd&quot;&lt;e&gt;f');
        $email = $this->db->selectSingle('SELECT email FROM %%ACCOUNTS%% WHERE id = :id;', [':id' => $id], 'email');

        $this->assertSame($id, $this->login($email, 'ab&cd"<e>f')->getId());

        $hash = $this->db->selectSingle('SELECT password FROM %%ACCOUNTS%% WHERE id = :id;', [':id' => $id], 'password');
        $this->assertTrue(password_verify('ab&cd"<e>f', $hash));
        $this->assertFalse(password_verify('ab&amp;cd&quot;&lt;e&gt;f', $hash));
    }

    public function testOldSettingsHashWithQuestionMarksStillLogsIn(): void
    {
        $id = $this->account('p??ssw??rd1');
        $email = $this->db->selectSingle('SELECT email FROM %%ACCOUNTS%% WHERE id = :id;', [':id' => $id], 'email');

        $this->assertSame($id, $this->login($email, 'pässwörd1')->getId());
    }

    public function testWrongPasswordIsRejected(): void
    {
        $id = $this->account('secret-one');
        $email = $this->db->selectSingle('SELECT email FROM %%ACCOUNTS%% WHERE id = :id;', [':id' => $id], 'email');

        $this->expectException(BadCredentialsException::class);
        $this->login($email, 'secret-two');
    }

    public function testLoginByAccountIdIsRefused(): void
    {
        $id = $this->account('secret-one');

        foreach ([(string) $id, '000' . $id] as $identifier) {
            try {
                $this->login($identifier, 'secret-one');
                $this->fail("login by id $identifier must fail");
            } catch (UserNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        // the id lookup stays available for Symfony's own session refresh
        $this->assertSame($id, self::container()->get(AccountProvider::class)->loadUserByIdentifier((string) $id)->getId());
    }

    /** @param array<string, mixed> $input */
    private function call(object $controller, string $method, array $input, string $httpMethod = 'GET'): string
    {
        $container = self::container();
        $controller->withGameServices($this->db, $this->configs, $this->state);
        $controller->setSecurityServices($container->get(AuthRateLimits::class), $container->get(CanonicalUrl::class));
        $request = Request::create('/', $httpMethod, [], [], [], ['REMOTE_ADDR' => '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254)]);
        // pages render the CSRF meta tag, which needs a session
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $this->state->request = $request;
        $this->state->requestPost = $input;
        $this->state->requestInput = $input;
        $this->state->requestQuery = $input;
        $controller->initialize();
        ob_start();
        try {
            $controller->$method();
        } catch (EndOfRequest) {
        } finally {
            $output = (string) ob_get_clean();
            $container->get('request_stack')->pop();
        }

        return $output;
    }

    public function testVerificationLinkCreatesTheAccountOnlyOnce(): void
    {
        $this->db->insert(
            "INSERT INTO %%ACCOUNTS_VALID%% SET validationKey = 'once-key', accountname = 'oncer', password = :p, email = 'oncer@example.com', date = :t, ip = '127.0.0.1', language = 'en', timezone = 'Europe/Berlin', referralID = 0;",
            [':p' => password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), ':t' => time()],
        );
        $validationId = $this->db->lastInsertId();
        $this->db->nativeQuery('SET foreign_key_checks = 0');

        for ($i = 0; $i < 3; $i++) {
            $this->call(new VertifyController(), 'json', ['i' => $validationId, 'k' => 'once-key']);
        }

        $this->assertSame(1, (int) $this->db->selectSingle("SELECT COUNT(*) AS c FROM %%ACCOUNTS%% WHERE email = 'oncer@example.com';", [], 'c'));
        $this->assertSame(0, (int) $this->db->selectSingle("SELECT COUNT(*) AS c FROM %%ACCOUNTS_VALID%% WHERE validationKey = 'once-key';", [], 'c'));
        $this->db->nativeQuery('SET foreign_key_checks = 1');
    }

    public function testVerificationDoesNotCreateASecondAccountForAUsedAddress(): void
    {
        $this->db->insert(
            "INSERT INTO %%ACCOUNTS_VALID%% SET validationKey = 'dup-key', accountname = 'duper', password = :p, email = :e, date = :t, ip = '127.0.0.1', language = 'en', timezone = 'Europe/Berlin', referralID = 0;",
            [':p' => password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), ':e' => 'taken' . random_int(1, 99999) . '@example.com', ':t' => time()],
        );
        $validationId = $this->db->lastInsertId();
        $email = $this->db->selectSingle('SELECT email FROM %%ACCOUNTS_VALID%% WHERE validationID = :i;', [':i' => $validationId], 'email');
        (new \App\Game\Core\AccountUtil($this->db, $this->configs, $this->state))->createAccount('already', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), $email, 'en');

        $this->call(new VertifyController(), 'json', ['i' => $validationId, 'k' => 'dup-key']);

        $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ACCOUNTS%% WHERE email = :e;', [':e' => $email], 'c'));
    }

    public function testResetKeyIsNotUsedUpByAGetAndWorksOnceByPost(): void
    {
        $id = $this->account('old-password');
        $this->db->insert('INSERT INTO %%LOSTPASSWORD%% SET accountID = :a, `key` = :k, `time` = :t, hasChanged = 0, fromIP = :ip;', [':a' => $id, ':k' => str_repeat('ab', 16), ':t' => time(), ':ip' => '127.0.0.1']);
        $input = ['u' => $id, 'k' => str_repeat('ab', 16)];
        $hash = fn(): string => (string) $this->db->selectSingle('SELECT password FROM %%ACCOUNTS%% WHERE id = :id;', [':id' => $id], 'password');
        $before = $hash();

        $this->call(new LostPasswordController(), 'newPassword', $input, 'GET');
        $this->assertSame($before, $hash());
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT hasChanged FROM %%LOSTPASSWORD%% WHERE accountID = :a;', [':a' => $id], 'hasChanged'));

        $this->call(new LostPasswordController(), 'newPassword', $input, 'POST');
        $afterFirst = $hash();
        $this->assertNotSame($before, $afterFirst);

        $this->call(new LostPasswordController(), 'newPassword', $input, 'POST');
        $this->assertSame($afterFirst, $hash());
    }

    public function testAccountRegisterRefusesAnUnknownUniverse(): void
    {
        $account = $this->account('secret-one');
        $this->state->ACCOUNT = ['id' => $account, 'authlevel' => 0, 'accountname' => 'x', 'timezone' => 'Europe/Berlin', 'lang' => 'en', 'referralID' => 0, 'db_deaktjava' => 0, 'signalColors' => []];

        // before the fix an unknown universe ended in an exception (HTTP 503); now it is an ordinary error page
        $this->call(new AccountRegister(), 'send', ['uni' => 999, 'username' => 'nobody1']);

        $this->assertSame(0, (int) $this->db->selectSingle("SELECT COUNT(*) AS c FROM %%USERS%% WHERE username = 'nobody1';", [], 'c'));
    }

    public function testSessionRowOfAnActivePlayerSurvivesLongAfterTheLogin(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.9.8.7']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->state->request = $request;
        $manager = new \App\Game\Core\SessionManager($this->db, $this->configs, $this->state);

        $session = $manager->create();
        $session->accountId = 4242;
        $session->userId = 0;
        $session->save(true);
        $sessionId = $manager->id();
        $row = fn(): array|false => $this->db->selectSingle('SELECT created, lastonline FROM %%SESSION%% WHERE sessionID = :id;', [':id' => $sessionId]);
        $this->assertNotFalse($row());

        // the login is 20 hours old, but the player is active right now: the session must not be cut at 12 hours since login
        $this->db->update('UPDATE %%SESSION%% SET created = :c WHERE sessionID = :id;', [':c' => time() - 20 * 3600, ':id' => $sessionId]);
        $session->save(true);

        $stored = $row();
        $this->assertNotFalse($stored);
        $this->assertLessThan(120, abs(time() - (int) $stored['lastonline']));
        $this->assertLessThan(21 * 3600, time() - (int) $stored['created']);
    }
}

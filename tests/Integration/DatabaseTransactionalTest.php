<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Database;
use App\Tests\Support\GameTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Exception;
use PDOException;
use RuntimeException;

/**
 * Database::transactional(): commit on success, rollback and rethrow on errors, repeat the whole closure on deadlock
 * (1213) and lock wait timeout (1205, provoked for real with a second connection), give up after the last attempt.
 * Also pins the connection setup: the game runs in READ COMMITTED.
 *
 * The rows live in a scratch table that exists only inside the test (the DDL commits the test transaction of
 * GameTestCase, so the table is dropped explicitly).
 */
final class DatabaseTransactionalTest extends GameTestCase
{
    private const TABLE = 'loadtest_probe';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->query('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
        $this->db->query('INSERT INTO ' . self::TABLE . ' (id, value) VALUES (1, 0), (2, 0)');
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        parent::tearDown();
    }

    /** a deadlock the way Database::_query() reports it: own Exception, MySQL error number as code, PDOException behind it */
    private function mysqlError(int $number): Exception
    {
        $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: ' . $number);
        $pdo->errorInfo = ['40001', $number, 'simulated'];

        return new Exception('query failed', $number, $pdo);
    }

    private function value(int $id): int
    {
        return (int) $this->db->selectSingle('SELECT value FROM ' . self::TABLE . ' WHERE id = :id', [':id' => $id], 'value');
    }

    public function testConnectionRunsInReadCommitted(): void
    {
        $this->assertSame('READ-COMMITTED', $this->db->selectSingle('SELECT @@transaction_isolation AS level', [], 'level'));
    }

    public function testCommitsAndReturnsTheResult(): void
    {
        $result = $this->db->transactional(function (): string {
            $this->db->update('UPDATE ' . self::TABLE . ' SET value = 5 WHERE id = 1');

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(5, $this->value(1));
    }

    public function testRetriesTheWholeClosureAfterADeadlockAndRollsTheFailedAttemptBack(): void
    {
        $attempts = [];
        $this->db->transactional(function (int $attempt) use (&$attempts): void {
            $attempts[] = $attempt;
            $this->db->update('UPDATE ' . self::TABLE . ' SET value = value + 1 WHERE id = 1');
            if ($attempt < 3) {
                throw $this->mysqlError(Database::ERROR_DEADLOCK);
            }
        }, 3, 0);

        $this->assertSame([1, 2, 3], $attempts);
        $this->assertSame(1, $this->value(1), 'the two failed attempts left nothing behind');
    }

    public function testGivesUpAfterTheLastAttemptWithTheOriginalException(): void
    {
        $runs = 0;
        $error = $this->mysqlError(Database::ERROR_DEADLOCK);
        try {
            $this->db->transactional(function () use (&$runs, $error): void {
                $runs++;
                $this->db->update('UPDATE ' . self::TABLE . ' SET value = 9 WHERE id = 2');
                throw $error;
            }, 3, 0);
            $this->fail('exception expected');
        } catch (Exception $e) {
            $this->assertSame($error, $e);
        }

        $this->assertSame(3, $runs);
        $this->assertSame(0, $this->value(2));
        $this->assertSame(Database::ERROR_DEADLOCK, Database::errorNumber($error));
    }

    public function testOtherErrorsRollBackAndAreRethrownWithoutRetry(): void
    {
        $runs = 0;
        try {
            $this->db->transactional(function () use (&$runs): void {
                $runs++;
                $this->db->update('UPDATE ' . self::TABLE . ' SET value = 7 WHERE id = 1');
                throw new RuntimeException('boom');
            }, 3, 0);
            $this->fail('exception expected');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(1, $runs);
        $this->assertSame(0, $this->value(1));
    }

    public function testNonRetryableMysqlErrorIsNotRepeated(): void
    {
        $runs = 0;
        try {
            $this->db->transactional(function () use (&$runs): void {
                $runs++;
                throw $this->mysqlError(1062); // duplicate key
            }, 3, 0);
            $this->fail('exception expected');
        } catch (Exception) {
        }

        $this->assertSame(1, $runs);
        $this->assertFalse(Database::isRetryable($this->mysqlError(1062)));
        $this->assertTrue(Database::isRetryable($this->mysqlError(Database::ERROR_LOCK_WAIT_TIMEOUT)));
    }

    public function testRealLockWaitTimeoutIsRetriedUntilTheOtherTransactionLetsGo(): void
    {
        /** @var Connection $connection */
        $connection = self::container()->get(Connection::class);
        $other = DriverManager::getConnection($connection->getParams());
        $other->executeStatement('START TRANSACTION');
        $other->executeStatement('UPDATE ' . self::TABLE . ' SET value = 100 WHERE id = 1');

        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        $attempts = 0;
        try {
            $this->db->transactional(function (int $attempt) use (&$attempts, $other): void {
                $attempts = $attempt;
                if ($attempt === 2) {
                    $other->executeStatement('COMMIT'); // the blocker finishes while attempt 1 timed out
                }
                $this->db->update('UPDATE ' . self::TABLE . ' SET value = value + 1 WHERE id = 1');
            }, 3, 0);
        } finally {
            $this->db->query('SET SESSION innodb_lock_wait_timeout = 50');
            $other->close();
        }

        $this->assertSame(2, $attempts, 'attempt 1 ran into the real 1205, attempt 2 got through');
        $this->assertSame(101, $this->value(1));
    }

    public function testRealLockWaitTimeoutSurfacesAfterTheLastAttempt(): void
    {
        /** @var Connection $connection */
        $connection = self::container()->get(Connection::class);
        $other = DriverManager::getConnection($connection->getParams());
        $other->executeStatement('START TRANSACTION');
        $other->executeStatement('UPDATE ' . self::TABLE . ' SET value = 100 WHERE id = 2');

        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->db->transactional(function (): void {
                $this->db->update('UPDATE ' . self::TABLE . ' SET value = 1 WHERE id = 2');
            }, 2, 0);
            $this->fail('exception expected');
        } catch (Exception $e) {
            $this->assertSame(Database::ERROR_LOCK_WAIT_TIMEOUT, Database::errorNumber($e));
        } finally {
            $this->db->query('SET SESSION innodb_lock_wait_timeout = 50');
            $other->executeStatement('ROLLBACK');
            $other->close();
        }
    }
}

<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Core;

use Exception;
use PDO;
use PDOException;
use PDOStatement;

class Database
{
    /** Connection setup of the game (charset + sql_mode without ONLY_FULL_GROUP_BY etc.). */
    public const INIT_COMMAND = "SET CHARACTER SET utf8mb4, NAMES utf8mb4, sql_mode = 'STRICT_ALL_TABLES'";

    private PDO $dbHandle;
    private array $dbTableNames = [];
    /** connection settings this instance was created with (host, port, user, userpw, databasename, ...) */
    private array $connectionConfig = [];
    private int $lastInsertId = 0;
    private int $rowCount = 0;
    private int $queryCounter = 0;
    public function getConnectionConfig(): array
    {
        return $this->connectionConfig;
    }

    public function getDbTableNames(): array
    {
        return $this->dbTableNames;
    }

    /** table prefix of the game tables (formerly DB_PREFIX) */
    public function getTablePrefix(): string
    {
        return (string) $this->connectionConfig['tableprefix'];
    }

    /** schema name (formerly DB_NAME) */
    public function getDatabaseName(): string
    {
        return (string) $this->connectionConfig['databasename'];
    }

    /**
     * Shares Symfony's DBAL connection (same init command: config/packages/doctrine.yaml); without one
     * (no Symfony context) it connects itself with the settings from DatabaseConfig.
     */
    public function __construct(
        DatabaseConfig $config,
        private Singleton $state,
        ?\Doctrine\DBAL\Connection $connection = null,
        /** debug mode: every query is recorded in $state->queryLog for the profiler (GameDatabaseCollector) */
        private bool $debug = false,
    ) {
        $database = $config->get();
        $this->connectionConfig = $database;
        $db = $connection !== null ? $connection->getNativeConnection() : new PDO(
            "mysql:host=" . $database['host'] . ";port=" . $database['port'] . ";dbname=" . $database['databasename'],
            $database['user'],
            $database['userpw'],
            [PDO::MYSQL_ATTR_INIT_COMMAND => self::INIT_COMMAND]
        );
        //error behaviour
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        // $db->query("set character set utf8");
        // $db->query("set names utf8");
        // $db->query("SET sql_mode = 'STRICT_ALL_TABLES'");
        $this->dbHandle = $db;
        $dbTableNames = [];
        include 'src/Game/Support/tables.php';
        foreach ($dbTableNames as $key => $name) {
            $this->dbTableNames['keys'][]   = '%%' . $key . '%%';
            $this->dbTableNames['names'][]  = $name;
        }
    }

    public function lastInsertId(): int
    {
        return $this->lastInsertId;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    private function _query(string $qry, array $params, string $type): bool|PDOStatement
    {
        $start = microtime(true);
        if (in_array($type, ["insert", "select", "update", "delete", "replace"]) === false) {
            throw new Exception("Unsupported Query Type");
        }

        $this->lastInsertId = 0;
        $this->rowCount = 0;
        $qry = preg_replace('/(?<!:)\bsystem\b(?!`)/', '`system`', $qry);
        $qry = preg_replace('/(?<!:)\brank\b(?!`)/', '`rank`', $qry);
        $qry    = str_replace($this->dbTableNames['keys'], $this->dbTableNames['names'], $qry);
        /** @var PDOStatement $stmt */
        $stmt   = $this->dbHandle->prepare($qry);
        if (isset($params[':limit']) || isset($params[':offset'])) {
            foreach ($params as $param => $value) {
                if ($param == ':limit' || $param == ':offset') {
                    $stmt->bindValue($param, (int) $value, PDO::PARAM_INT);
                } elseif (is_null($value)) {
                    $stmt->bindValue($param, null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue($param, $value, PDO::PARAM_STR);
                }
            }
        }

        try {
            $success = (
                count($params) !== 0 && !isset($params[':limit']) && !isset($params[':offset'])
            ) ? $stmt->execute($params) : $stmt->execute();
            $this->state->time['db'] += microtime(true) - $start;
        } catch (PDOException $e) {
            $errorCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            throw new Exception(
                $e->getMessage() . "<br><br>Query-Code: " . str_replace(
                    array_keys($params),
                    array_values($params),
                    $qry
                ), $errorCode, $e
            );
        }

        $this->queryCounter++;
        $this->logQuery($qry, $params, $type, $start);
        if (!$success) {
            return false;
        }

        if ($type === "insert") {
            $this->lastInsertId = $this->dbHandle->lastInsertId();
        }
        $this->rowCount = $stmt->rowCount();
        return ($type === "select") ? $stmt : true;
    }

    private function getQueryType(string $qry): string
    {
        if (!preg_match('!^(\S+)!', $qry, $match)) {
            throw new Exception("Invalid query $qry!");
        }

        if (!isset($match[1])) {
            throw new Exception("Invalid query $qry!");
        }

        return strtolower($match[1]);
    }

    public function delete(string $qry, array $params = []): bool
    {
        if (($type = $this->getQueryType($qry)) !== "delete") {
            throw new Exception("Incorrect Delete Query");
        }

        return $this->_query($qry, $params, $type);
    }

    public function replace(string $qry, array $params = []): bool
    {
        if (($type = $this->getQueryType($qry)) !== "replace") {
            throw new Exception("Incorrect Replace Query");
        }

        return $this->_query($qry, $params, $type);
    }

    public function update(string $qry, array $params = []): bool
    {
        if (($type = $this->getQueryType($qry)) !== "update") {
            throw new Exception("Incorrect Update Query");
        }

        return $this->_query($qry, $params, $type);
    }

    public function insert(string $qry, array $params = []): bool
    {
        if (($type = $this->getQueryType($qry)) !== "insert") {
            throw new Exception("Incorrect Insert Query");
        }

        return $this->_query($qry, $params, $type);
    }

    public function select(string $qry, array $params = []): array
    {
        if (($type = $this->getQueryType($qry)) !== "select") {
            throw new Exception("Incorrect Select Query");
        }

        $stmt = $this->_query($qry, $params, $type);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function selectSingle(string $qry, array $params = [], ?string $field = null): mixed
    {
        if (($type = $this->getQueryType($qry)) !== "select") {
            throw new Exception("Incorrect Select Query");
        }

        $stmt = $this->_query($qry, $params, $type);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return (empty($field) || (empty($res))) ? $res : $res[$field];
    }

    public function startTransaction(): void
    {
        $this->query("START TRANSACTION;");
    }

    public function commit(): void
    {
        $this->query("COMMIT;");
    }

    public function rollback(): void
    {
        $this->query("ROLLBACK");
    }

    /**
     * Lists column values of a table
     * with desired key from the
     * database as an array.
     *
     * @param  string       $table
     * @param  string       $column
     * @param  string       $key
     * @return array
     */
    public function lists(string $table, string $column, ?string $key = null): array
    {
        $selects = implode(', ', is_null($key) ? [$column] : [$column, $key]);
        $qry = "SELECT {$selects} FROM %%{$table}%%;";
        $stmt = $this->_query($qry, [], 'select');
        $results = [];
        if (is_null($key)) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = $row[$column];
            }
        } else {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[$row[$key]] = $row[$column];
            }
        }

        return $results;
    }

    public function query(string $qry): void
    {
        $this->lastInsertId = 0;
        $this->rowCount = 0;
        $start = microtime(true);
        $this->rowCount = $this->dbHandle->exec($qry);
        $this->queryCounter++;
        $this->logQuery($qry, [], 'query', $start);
    }

    public function nativeQuery(string $qry): bool|array
    {
        $this->lastInsertId = 0;
        $this->rowCount = 0;
        $qry    = str_replace($this->dbTableNames['keys'], $this->dbTableNames['names'], $qry);
        $start = microtime(true);
        /** @var PDOStatement $stmt */
        $stmt   = $this->dbHandle->query($qry);
        $this->rowCount = $stmt->rowCount();
        $this->queryCounter++;
        $this->logQuery($qry, [], 'native', $start);
        return in_array($this->getQueryType($qry), ['select', 'show']) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : true;
    }

    /** debug mode only: query, parameters and duration for the profiler panel (GameDatabaseCollector) */
    private function logQuery(string $qry, array $params, string $type, float $start): void
    {
        if ($this->debug) {
            $this->state->queryLog ??= [];
            $this->state->queryLog[] = ['sql' => $qry, 'params' => $params, 'type' => $type, 'ms' => (microtime(true) - $start) * 1000];
        }
    }

    public function getQueryCounter(): int
    {
        return $this->queryCounter;
    }

    public function formatDate(int $time): string
    {
        return date('Y-m-d H:i:s', floor($time));
    }

    public function quote(string $str): string
    {
        return $this->dbHandle->quote($str);
    }

    public function log(string $qry, array $params = []): string
    {
        $qry    = str_replace($this->dbTableNames['keys'], $this->dbTableNames['names'], $qry);
        $parameters = [];
        foreach ($params as $key => $value) {
            $parameters['keys'][]       = $key;
            $parameters['values'][]     = str_starts_with($value, "%") ? "'$value'" : "$value";
        }
        $qry = str_replace($parameters['keys'], $parameters['values'], $qry);
        error_log($qry);
        return $qry;
    }

    public function getClientVersion(): mixed
    {
        return $this->dbHandle->getAttribute(PDO::ATTR_CLIENT_VERSION);
    }
}

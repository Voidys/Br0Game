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

/**
 * Database dump and restore for backups (DumpCronjob, admin): uses mysqldump/mysql when the binaries exist, else a pure-PHP dumper (DROP/CREATE TABLE plus INSERT blocks) and executes the dump statement by statement.
 */
class SQLDumper
{
    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Writes the given tables (structure and data) as SQL to the file.
     * @param string[] $dbTables full table names (with prefix).
     * @param string $filePath target file.
     */
    public function dumpTablesToFile($dbTables, $filePath)
    {
        $dbTables = $this->validTables($dbTables);
        if ($this->canNative('mysqldump')) {
            return $this->nativeDumpToFile($dbTables, $filePath);
        } else {
            return $this->softwareDumpToFile($dbTables, $filePath);
        }
    }

    /**
     * Exact table names of the game (configured prefix) from the database; anything else in the request (other tables,
     * option-like strings such as `--host=...`) is rejected.
     *
     * @param mixed $requested table names from the caller
     * @return list<string> the requested names, deduplicated
     * @throws \InvalidArgumentException for an empty list or a name that is no game table
     */
    private function validTables($requested): array
    {
        $prefix = $this->db->getTablePrefix();
        $known = [];
        foreach ($this->db->nativeQuery('SHOW TABLE STATUS FROM `' . $this->db->getDatabaseName() . '`;') as $table) {
            if (str_starts_with((string) $table['Name'], $prefix)) {
                $known[] = (string) $table['Name'];
            }
        }
        $requested = is_array($requested) ? array_values(array_unique(array_map('strval', $requested))) : [];
        if ($requested === [] || array_diff($requested, $known) !== []) {
            throw new \InvalidArgumentException('Invalid table selection for the dump.');
        }

        return $requested;
    }

    private function setTimelimit()
    {
        set_time_limit(600); // 10 Minutes
    }

    private function canNative($command)
    {
        return function_exists('shell_exec') && function_exists('escapeshellarg') && shell_exec("which " . $command) !== "";
    }

    /**
     * Dumps the given tables with the mysqldump binary into $filePath (structure + complete inserts, primary-key
     * order, hex blobs, no tablespace info: that needs the PROCESS privilege). The password goes into a private
     * temporary option file (`--defaults-file`, mode 0600, removed afterwards; it also keeps a per-user ~/.my.cnf from overriding the credentials): not on the command line and not
     * in MYSQL_PWD, which MySQL 8.4 clients ignore. All arguments are shell-escaped.
     *
     * @param list<string> $dbTables prefixed table names
     * @throws \Exception with mysqldump's error output when the dump fails
     */
    private function nativeDumpToFile($dbTables, $filePath)
    {
        $database = $this->db->getConnectionConfig();

        $optionFile = tempnam(sys_get_temp_dir(), 'mydump');
        if ($optionFile === false || file_put_contents($optionFile, "[client]\npassword=\"" . addcslashes((string) $database['userpw'], "\\\"") . "\"\n") === false) {
            throw new Exception('Cannot write the temporary option file for mysqldump.');
        }
        chmod($optionFile, 0600);

        try {
            $dbTables = array_map('escapeshellarg', $dbTables);
            $sqlDump = shell_exec(
                "mysqldump --defaults-file=" . escapeshellarg($optionFile)
                . " --host=" . escapeshellarg($database['host']) . " --port=" . ((int) $database['port'])
                . " --user=" . escapeshellarg($database['user'])
                . " --no-tablespaces --no-create-db --order-by-primary --add-drop-table --comments --complete-insert --hex-blob "
                . escapeshellarg($database['databasename']) . " -- " . implode(' ', $dbTables)
                . " 2>&1 1> " . escapeshellarg($filePath),
            );
        } finally {
            unlink($optionFile);
        }
        if ($sqlDump !== null && $sqlDump !== '') { #mysqldump error
            if (is_file($filePath)) {
                unlink($filePath);
            }
            throw new Exception($sqlDump);
        }

        return '';
    }

    /**
     * Pure-PHP dump: per table DROP TABLE IF EXISTS, SHOW CREATE TABLE and chunked INSERTs; numbers unquoted, other values quoted. Sets a 10 minute time limit.
     */
    private function softwareDumpToFile($dbTables, $filePath)
    {
        $this->setTimelimit();

        $database	= $this->db->getConnectionConfig();
        $gameVersion	= $this->configs->get()->version;
        $fp	= fopen($filePath, 'w');
        $this->writeDumpHeader($fp, $database, $gameVersion);

        foreach ($dbTables as $dbTable) {
            $this->dumpTable($fp, $dbTable);
        }
        fwrite($fp, "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on " . date("Y-d-m H:i:s"));
        fclose($fp);

        return filesize($filePath) !== 0;
    }

    /**
     * Writes the comment header and the session settings (character set, time zone, checks off) of a dump.
     *
     * @param resource $fp open dump file
     * @param array<string, mixed> $database connection config (host, databasename)
     * @param string $gameVersion game version for the header comment
     */
    private function writeDumpHeader($fp, array $database, string $gameVersion): void
    {
        fwrite($fp, "-- MySQL dump | V0Game dumper v{$gameVersion}
--
-- Host: {$database['host']}    Database: {$database['databasename']}
-- ------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

");
    }

    /**
     * Writes one table: DROP TABLE IF EXISTS, SHOW CREATE TABLE and the rows as INSERT statements of 50 rows each
     * (number columns unquoted, other values quoted, NULL as NULL); an empty table only gets a comment.
     *
     * @param resource $fp open dump file
     * @param string $dbTable prefixed table name
     */
    private function dumpTable($fp, string $dbTable): void
    {
        $db	= $this->db;

        fwrite($fp, "--\n-- Table structure for table `{$dbTable}`\n--\n\nDROP TABLE IF EXISTS `{$dbTable}`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;\n\n");

        $createTable	= $db->nativeQuery("SHOW CREATE TABLE `" . $dbTable . "`");
        $createTableSql = isset($createTable['Create Table'])
            ? $createTable['Create Table'] : (
                #old mysql clients
                isset($createTable[0]['Create Table'])
                    ? $createTable[0]['Create Table']
                    : false
            );

        if ($createTableSql === false) {
            throw new Exception("Error after executing SHOW CREATE TABLE " . $dbTable . "! Can't find key 'Create Table' in the results. Available data: \n\n" . print_r($createTable, true));
        }

        fwrite($fp, $createTableSql . ';');
        fwrite($fp, "\n\n/*!40101 SET character_set_client = @saved_cs_client */;");

        $sql = "SELECT COUNT(*) as state FROM `" . $dbTable . "`;";

        $count	= $db->nativeQuery($sql);
        if ($count[0]['state'] == 0) {
            fwrite($fp, "\n\n--\n-- No data for table `{$dbTable}`\n--\n\n");
            return;
        }

        fwrite($fp, "
			
--
-- Dumping data for table `{$dbTable}`
--

LOCK TABLES `{$dbTable}` WRITE;
/*!40000 ALTER TABLE `{$dbTable}` DISABLE KEYS */;

");
        $this->dumpRows($fp, $dbTable);
        fwrite($fp, ";
			
/*!40000 ALTER TABLE `{$dbTable}` ENABLE KEYS */;
UNLOCK TABLES;

");
    }

    /**
     * Writes the rows of a table as INSERT statements of 50 rows each: number columns unquoted, other values quoted
     * (NULL as NULL).
     *
     * @param resource $fp open dump file
     * @param string $dbTable prefixed table name
     */
    private function dumpRows($fp, string $dbTable): void
    {
        $db	= $this->db;
        $integerTypes	= ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double', 'real'];
        $numColumns	= [];
        $firstRow	= true;
        $columnsData	= $db->nativeQuery("SHOW COLUMNS FROM `" . $dbTable . "`");
        $columnNames	= [];
        foreach ($columnsData as $columnData) {
            $columnNames[]	= $columnData['Field'];
            foreach ($integerTypes as $type) {
                if (strpos($columnData['Type'], $type . '(') !== false) {
                    $numColumns[]	= $columnData['Field'];
                    break;
                }
            }
        }

        $insertInto	= "INSERT INTO `{$dbTable}` (`" . implode("`, `", $columnNames) . "`) VALUES\r\n";

        fwrite($fp, $insertInto);
        $i = 0;
        $tableData	= $db->select("SELECT * FROM `" . $dbTable . "`");
        foreach ($tableData as $tableRow) {
            $rowData = [];
            $i++;
            if (($i % 50) === 0) {
                $firstRow	= true;
                fwrite($fp, ";\r\n");
                fwrite($fp, $insertInto);
            }

            if (!$firstRow) {
                fwrite($fp, ",\r\n");
            } else {
                $firstRow = false;
            }

            foreach ($tableRow as $colum => $value) {
                if (in_array($colum, $numColumns)) {
                    $rowData[]	= $value === null ? 'NULL' : $value;
                } else {
                    $rowData[]	= $value === null ? 'NULL' : $db->quote($value);
                }
            }
            fwrite($fp, "(" . implode(", ", $rowData) . ")");
        }
    }
}

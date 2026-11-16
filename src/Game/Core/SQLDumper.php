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
        if ($this->canNative('mysqldump')) {
            return $this->nativeDumpToFile($dbTables, $filePath);
        } else {
            return $this->softwareDumpToFile($dbTables, $filePath);
        }
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
     * order, hex blobs). The password goes through MYSQL_PWD for MySQL ≥ 5.5 so it doesn't appear in the process list;
     * all arguments are shell-escaped.
     *
     * @param list<string> $dbTables prefixed table names
     * @throws \Exception with mysqldump's error output when the dump fails
     */
    private function nativeDumpToFile($dbTables, $filePath)
    {
        $database	= [];
        $database = $this->db->getConnectionConfig();

        $dbVersion	= $this->db->selectSingle('SELECT @@version', [], '@@version');
        if (version_compare($dbVersion, '5.5') >= 0) {
            putenv('MYSQL_PWD='.$database['userpw']);
            $passwordArgument = '';
        } else {
            $passwordArgument = "--password='".escapeshellarg($database['userpw'])."'";
        }

        $dbTables	= array_map('escapeshellarg', $dbTables);
        $sqlDump	= shell_exec("mysqldump --host=".escapeshellarg($database['host'])." --port=".((int) $database['port'])." --user=".escapeshellarg($database['user'])." ".$passwordArgument." --no-create-db --order-by-primary --add-drop-table --comments --complete-insert --hex-blob ".escapeshellarg($database['databasename'])." ".implode(' ', $dbTables)." 2>&1 1> ".$filePath);
        if (strlen($sqlDump) !== 0) { #mysqldump error
            throw new Exception($sqlDump);
        }
        return $sqlDump;
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

-- Dump completed on ".date("Y-d-m H:i:s"));
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
        fwrite($fp, "-- MySQL dump | Br0Game dumper v{$gameVersion}
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

        $createTable	= $db->nativeQuery("SHOW CREATE TABLE ".$dbTable);
        $createTableSql = isset($createTable['Create Table']) ?
            $createTable['Create Table'] : (
                #old mysql clients
                isset($createTable[0]['Create Table']) ?
                    $createTable[0]['Create Table'] :
                    false
            );

        if ($createTableSql === false) {
            throw new Exception("Error after executing SHOW CREATE TABLE ".$dbTable."! Can't find key 'Create Table' in the results. Available data: \n\n".print_r($createTable, true));
        }

        fwrite($fp, $createTableSql.';');
        fwrite($fp, "\n\n/*!40101 SET character_set_client = @saved_cs_client */;");

        $sql = "SELECT COUNT(*) as state FROM ".$dbTable.";";

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
        $columnsData	= $db->nativeQuery("SHOW COLUMNS FROM `".$dbTable."`");
        $columnNames	= [];
        foreach ($columnsData as $columnData) {
            $columnNames[]	= $columnData['Field'];
            foreach ($integerTypes as $type) {
                if (strpos($columnData['Type'], $type.'(') !== false) {
                    $numColumns[]	= $columnData['Field'];
                    break;
                }
            }
        }

        $insertInto	= "INSERT INTO `{$dbTable}` (`".implode("`, `", $columnNames)."`) VALUES\r\n";

        fwrite($fp, $insertInto);
        $i = 0;
        $tableData	= $db->select("SELECT * FROM ".$dbTable);
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
            fwrite($fp, "(".implode(", ", $rowData).")");
        }
    }

    /**
     * Replays a dump file: with the mysql client when available (the shell output is treated as an error), otherwise by splitting the file at ";\r\n" and running each statement. Sets a 10 minute time limit.
     * @throws Exception with the client's output on error.
     */
    public function restoreDatabase($filePath)
    {
        // Ugly.
        $this->setTimelimit();

        if ($this->canNative('mysql')) {
            $database	= [];
            $database = $this->db->getConnectionConfig();
            $sqlDump	= shell_exec("mysql --host='".escapeshellarg($database['host'])."' --port=".((int) $database['port'])." --user='".escapeshellarg($database['user'])."' --password='".escapeshellarg($database['userpw'])."' '".escapeshellarg($database['databasename'])."' < ".escapeshellarg($filePath)." 2>&1 1> /dev/null");
            if (strlen($sqlDump) !== 0) { #mysql error
                throw new Exception($sqlDump);
            }
        } else {
            $backupQuery	= explode(";\r\n", file_get_contents($filePath));
            foreach ($backupQuery as $query) {
                $this->db->nativeQuery($query);
            }
        }
    }
}

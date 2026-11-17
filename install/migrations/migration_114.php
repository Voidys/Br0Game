<?php

/**
 * Migration 114: Convert config and config_universe tables to key-value format
 */

require_once __DIR__ . '/vendor/autoload.php';

use PSpell\Config;
use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Database;

define('MODE', 'UPGRADE');
define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)) . '/');
set_include_path(ROOT_PATH);

\App\Legacy\GameBootstrap::run();

try {
    $migration = new Migration_114();
    $migration->up();
} catch (\Throwable $th) {
    $migration->down();
    error_log('Error: ' . $th->getMessage() . '.');
}

class Migration_114
{
    public function up()
    {
        $db = Database::get();
        $dbTableNames = $db->getDbTableNames();

        // Rename old tables
        $qry = 'RENAME TABLE `%%CONFIG%%` TO `%%CONFIG%%_old`;';
        $qry = str_replace($dbTableNames['keys'], $dbTableNames['names'], $qry);
        $db->query($qry);

        $qry = 'RENAME TABLE `%%CONFIG_UNIVERSE%%` TO `%%CONFIG_UNIVERSE%%_old`;';
        $qry = str_replace($dbTableNames['keys'], $dbTableNames['names'], $qry);
        $db->query($qry);

        // Create new key-value tables
        $qry = '
            CREATE TABLE `%%CONFIG%%` (
                `key` varchar(255) NOT NULL,
                `value` text,
                PRIMARY KEY (`key`)
            ) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        ';
        $qry = str_replace($dbTableNames['keys'], $dbTableNames['names'], $qry);
        $db->query($qry);

        $qry = '
            CREATE TABLE `%%CONFIG_UNIVERSE%%` (
                `uni` int(11) NOT NULL,
                `key` varchar(255) NOT NULL,
                `value` text,
                PRIMARY KEY (`uni`, `key`)
            ) ENGINE = InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        ';
        $qry = str_replace($dbTableNames['keys'], $dbTableNames['names'], $qry);
        $db->query($qry);

        $schemaBasic = new ConfigFieldSchema();
        $oldGlobalConfig = $db->selectSingle('SELECT * FROM `%%CONFIG%%_old`;');
        foreach ($schemaBasic->getAllFields() as $fieldname => $field) {
            $value = $oldGlobalConfig[$fieldname] ?? $field['default'] ?? '';
            if ($value === null) {
                $value = '';
            }
            $db->insert('INSERT INTO `%%CONFIG%%` (`key`, `value`) VALUES (:key, :value);', [
                ':key' => $fieldname,
                ':value' => $value,
            ]);
        }

        $schemaUniverse = new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_UNI);
        $oldUniverseConfigs = $db->select('SELECT * FROM `%%CONFIG_UNIVERSE%%_old`;');
        foreach ($oldUniverseConfigs as $oldConfig) {
            $uni = $oldConfig['uni'];
            foreach ($schemaUniverse->getAllFields() as $fieldname => $field) {
                $value = $oldConfig[$fieldname] ?? $field['default'] ?? '';
                if ($value === null) {
                    $value = '';
                }
                $db->insert('INSERT INTO `%%CONFIG_UNIVERSE%%` (`uni`, `key`, `value`) VALUES (:uni, :key, :value);', [
                    ':uni' => $uni,
                    ':key' => $fieldname,
                    ':value' => $value,
                ]);
            }
        }
    }

    public function down()
    {
        $db = Database::get();
        $dbTableNames = $db->getDbTableNames();

        // Drop new tables
        $qry = 'DROP TABLE IF EXISTS `%%CONFIG%%`;';
        $qry = str_replace($dbTableNames['keys'], $dbTableNames['names'], $qry);
        $db->query($qry);

        $qry = 'DROP TABLE IF EXISTS `%%CONFIG_UNIVERSE%%`;';
        $qry = str_replace($dbTableNames['keys'], $dbTableNames['names'], $qry);
        $db->query($qry);

        // Rename old tables back
        $qry = 'RENAME TABLE `%%CONFIG%%_old` TO `%%CONFIG%%`;';
        $qry = str_replace($dbTableNames['keys'], $dbTableNames['names'], $qry);
        $db->query($qry);

        $qry = 'RENAME TABLE `%%CONFIG_UNIVERSE%%_old` TO `%%CONFIG_UNIVERSE%%`;';
        $qry = str_replace($dbTableNames['keys'], $dbTableNames['names'], $qry);
        $db->query($qry);
    }
}

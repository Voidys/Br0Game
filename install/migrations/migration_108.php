<?php

require_once __DIR__ . '/vendor/autoload.php';

define('MODE', 'UPGRADE');
define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)) . '/');
set_include_path(ROOT_PATH);

ini_set('log_errors', 'On');
ini_set('error_log', 'var/log/game.log');

require 'src/Game/Support/constants.php';

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\Core\Database;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Config;
use App\Game\Core\Cache;
use App\Game\Core\Singleton;

Singleton::load()->time['db'] = microtime(true);
$allConfigs = [];

$cache  = Cache::get();
$cache->add('vars', 'VarsBuildCache');
$vars = $cache->getData('vars');

function getConfig($universe)
{
    global $allConfigs;
    if (!isset($allConfigs[$universe])) {
        $allConfigs[$universe] = Config::get($universe);
    }
    return $allConfigs[$universe];
}

function getVars($universe)
{
    global $vars;

    $result = $vars[0];

    if (isset($vars[$universe])) {
        $result = array_replace_recursive($result, $vars[$universe]);
    }
    return $result;
}

try {
    $db = Database::get();

    $sql = "UPDATE %%VARS%% SET `ship_type` = 'military' WHERE `elementID` IN (204,205,206,207,211,213,214,215,216,218,220,221,222,223,224,225,226);";
    $db->update($sql);
    $sql = "UPDATE %%VARS%% SET `ship_type` = 'civil' WHERE `elementID` IN (202,203,208,210,212,217);";
    $db->update($sql);

    $sql = "CREATE TABLE " . DB_PREFIX . "advanced_stats_universe (
        `universe` tinyint(3) unsigned NOT NULL,
        `statkey` varchar(50) NOT NULL,
        `userId` int(10) unsigned NOT NULL,
        `username` varchar(32) NOT NULL DEFAULT '',
        `statvalue` bigint(20) NOT NULL DEFAULT '0',
        PRIMARY KEY (`universe`,`statkey`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $db->query($sql);

    $sql = "CREATE TABLE " . DB_PREFIX . "advanced_stats_new (
        `userId` int(10) unsigned NOT NULL,
        `universe` tinyint(3) unsigned NOT NULL,
        `statkey` varchar(50) NOT NULL,
        `statvalue` bigint(20) NOT NULL DEFAULT '0',
        PRIMARY KEY (`userId`,`universe`,`statkey`),
        INDEX `user_stat` (`userId`,`statkey`),
        INDEX `universe_stat` (`universe`,`statkey`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $db->query($sql);

    $sql = "CREATE TABLE " . DB_PREFIX . "advanced_stats_history_new (
        `timestamp` int(11) NOT NULL DEFAULT '0',
        `userId` int(10) unsigned NOT NULL,
        `universe` tinyint(3) unsigned NOT NULL,
        `statkey` varchar(50) NOT NULL,
        `statvalue` bigint(20) NOT NULL DEFAULT 0,
        PRIMARY KEY (`timestamp`,`userId`,`universe`,`statkey`),
        INDEX `timestamps` (`timestamp`),
        INDEX `user_stat` (`timestamp`,`userId`,`statkey`),
        INDEX `universe_stat` (`timestamp`,`universe`,`statkey`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $db->query($sql);

    $sql = "SELECT * FROM %%ADVANCED_STATS%%;";
    $currentStats = $db->select($sql);
    $sqlHonor = "SELECT honorpoints FROM %%USERS%% WHERE id = :userId;";

    // Bulk insert mit Batching
    $batchSize = 500;
    $rows = [];
    $params = [];
    foreach ($currentStats as $stats) {
        $universe = PlayerUtil::getUniverse($stats['userId']);

        $advanced_stats_dest = 0;
        $advanced_stats_lost = 0;
        $advanced_stats_met = 0;
        $advanced_stats_cris = 0;

        $localConfig = getConfig($universe);

        $fleetIntoDebris = $localConfig->fleet_debris_percentage;
        $defIntoDebris = $localConfig->def_debris_percentage;


        $varsData = getVars($universe);
        $pricelist = $varsData['pricelist'];
        $reslist = $varsData['reslist'];


        foreach ($reslist['fleet'] as $element) {
            $advanced_stats_dest += $stats[strtolower('DESTROYED_' . $element)] * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
            $advanced_stats_lost += $stats[strtolower('LOST_' . $element)] * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
            $advanced_stats_met += $stats[strtolower('DESTROYED_' . $element)] * $pricelist[$element]['cost'][RESOURCE_METAL] * ($fleetIntoDebris / 100);
            $advanced_stats_met += $stats[strtolower('LOST_' . $element)] * $pricelist[$element]['cost'][RESOURCE_METAL] * ($fleetIntoDebris / 100);
            $advanced_stats_cris += $stats[strtolower('DESTROYED_' . $element)] * $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * ($fleetIntoDebris / 100);
            $advanced_stats_cris += $stats[strtolower('LOST_' . $element)] * $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * ($fleetIntoDebris / 100);
        }
        foreach ($reslist['defense'] as $element) {
            $advanced_stats_dest += $stats[strtolower('DESTROYED_' . $element)] * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
            $advanced_stats_lost += $stats[strtolower('LOST_' . $element)] * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
            $advanced_stats_met += $stats[strtolower('DESTROYED_' . $element)] * $pricelist[$element]['cost'][RESOURCE_METAL] * ($defIntoDebris / 100);
            $advanced_stats_met += $stats[strtolower('LOST_' . $element)] * $pricelist[$element]['cost'][RESOURCE_METAL] * ($defIntoDebris / 100);
            $advanced_stats_cris += $stats[strtolower('DESTROYED_' . $element)] * $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * ($defIntoDebris / 100);
            $advanced_stats_cris += $stats[strtolower('LOST_' . $element)] * $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * ($defIntoDebris / 100);
        }

        $stats[strtolower(AdvancedStatsEnum::DESUNITS_REAL->name)] = $advanced_stats_dest;
        $stats[strtolower(AdvancedStatsEnum::LOSTUNITS_REAL->name)] = $advanced_stats_lost;
        $stats[strtolower(AdvancedStatsEnum::KBMETAL_REAL->name)] = $advanced_stats_met;
        $stats[strtolower(AdvancedStatsEnum::KBCRYSTAL_REAL->name)] = $advanced_stats_cris;
        $stats[strtolower(AdvancedStatsEnum::HONORPOINTS->name)] = $db->selectSingle($sqlHonor, [':userId' => $stats['userId']], 'honorpoints') ?: 0;

        foreach (AdvancedStatsEnum::cases() as $enum) {
            $key = strtolower($enum->name);
            if (array_key_exists($key, $stats) === true && $stats[$key] > 0) {
                $rows[] = '(?, ?, ?, ?)';
                $params[] = $stats['userId'];
                $params[] = $universe;
                $params[] = $enum->name;
                $params[] = $stats[$key];
            }

            if (count($rows) >= $batchSize) {
                $placeholders = implode(',', $rows);
                $sqlBulk = "INSERT INTO " . DB_PREFIX . "advanced_stats_new (userId, universe, statkey, statvalue)
                    VALUES {$placeholders}
                    ON DUPLICATE KEY UPDATE statvalue = VALUES(statvalue);";
                $db->insert($sqlBulk, $params);
                $rows = [];
                $params = [];
            }
        }
    }

    // restliche Zeilen einfügen
    if (!empty($rows)) {
        $placeholders = implode(',', $rows);
        $sqlBulk = "INSERT INTO " . DB_PREFIX . "advanced_stats_new (userId, universe, statkey, statvalue)
            VALUES {$placeholders}
            ON DUPLICATE KEY UPDATE statvalue = VALUES(statvalue);";
        $db->insert($sqlBulk, $params);
    }

    $sql = "SELECT * FROM %%ADVANCED_STATS_HISTORY%% WHERE `timestamp` >= UNIX_TIMESTAMP(CURDATE() - INTERVAL 7 DAY)";
    $currentStats = $db->select($sql);

    // Bulk insert für history
    $batchSize = 500;
    $rows = [];
    $params = [];
    foreach ($currentStats as $stats) {
        $universe = PlayerUtil::getUniverse($stats['userId']);

        $advanced_stats_dest = 0;
        $advanced_stats_lost = 0;
        $advanced_stats_met = 0;
        $advanced_stats_cris = 0;

        $localConfig = getConfig($universe);

        $fleetIntoDebris = $localConfig->fleet_debris_percentage;
        $defIntoDebris = $localConfig->def_debris_percentage;

        $varsData = getVars($universe);
        $pricelist = $varsData['pricelist'];
        $reslist = $varsData['reslist'];

        foreach ($reslist['fleet'] as $element) {
            $advanced_stats_dest += $stats[strtolower('DESTROYED_' . $element)] * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
            $advanced_stats_lost += $stats[strtolower('LOST_' . $element)] * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
            $advanced_stats_met += $stats[strtolower('DESTROYED_' . $element)] * $pricelist[$element]['cost'][RESOURCE_METAL] * ($fleetIntoDebris / 100);
            $advanced_stats_met += $stats[strtolower('LOST_' . $element)] * $pricelist[$element]['cost'][RESOURCE_METAL] * ($fleetIntoDebris / 100);
            $advanced_stats_cris += $stats[strtolower('DESTROYED_' . $element)] * $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * ($fleetIntoDebris / 100);
            $advanced_stats_cris += $stats[strtolower('LOST_' . $element)] * $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * ($fleetIntoDebris / 100);
        }
        foreach ($reslist['defense'] as $element) {
            $advanced_stats_dest += $stats[strtolower('DESTROYED_' . $element)] * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
            $advanced_stats_lost += $stats[strtolower('LOST_' . $element)] * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
            $advanced_stats_met += $stats[strtolower('DESTROYED_' . $element)] * $pricelist[$element]['cost'][RESOURCE_METAL] * ($defIntoDebris / 100);
            $advanced_stats_met += $stats[strtolower('LOST_' . $element)] * $pricelist[$element]['cost'][RESOURCE_METAL] * ($defIntoDebris / 100);
            $advanced_stats_cris += $stats[strtolower('DESTROYED_' . $element)] * $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * ($defIntoDebris / 100);
            $advanced_stats_cris += $stats[strtolower('LOST_' . $element)] * $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * ($defIntoDebris / 100);
        }

        $stats[strtolower(AdvancedStatsEnum::DESUNITS_REAL->name)] = $advanced_stats_dest;
        $stats[strtolower(AdvancedStatsEnum::LOSTUNITS_REAL->name)] = $advanced_stats_lost;
        $stats[strtolower(AdvancedStatsEnum::KBMETAL_REAL->name)] = $advanced_stats_met;
        $stats[strtolower(AdvancedStatsEnum::KBCRYSTAL_REAL->name)] = $advanced_stats_cris;

        foreach (AdvancedStatsEnum::cases() as $enum) {
            $key = strtolower($enum->name);
            if (array_key_exists($key, $stats) === true && $stats[$key] > 0) {
                $rows[] = '(?, ?, ?, ?, ?)';
                $params[] = $stats['timestamp'];
                $params[] = $stats['userId'];
                $params[] = $universe;
                $params[] = $enum->name;
                $params[] = $stats[$key];

                if (count($rows) >= $batchSize) {
                    $placeholders = implode(',', $rows);
                    $sqlBulk = "INSERT INTO " . DB_PREFIX . "advanced_stats_history_new (timestamp, userId, universe, statkey, statvalue)
                        VALUES {$placeholders}
                        ON DUPLICATE KEY UPDATE statvalue = VALUES(statvalue);";
                    $db->insert($sqlBulk, $params);
                    $rows = [];
                    $params = [];
                }
            }
        }
    }
    if (!empty($rows)) {
        $placeholders = implode(',', $rows);
        $sqlBulk = "INSERT INTO " . DB_PREFIX . "advanced_stats_history_new (timestamp, userId, universe, statkey, statvalue)
            VALUES {$placeholders}
            ON DUPLICATE KEY UPDATE statvalue = VALUES(statvalue);";
        $db->insert($sqlBulk, $params);
    }

    $sql = "RENAME TABLE " . DB_PREFIX . "advanced_stats TO " . DB_PREFIX . "advanced_stats_old, "
        . DB_PREFIX . "advanced_stats_history TO " . DB_PREFIX . "advanced_stats_history_old, "
        . DB_PREFIX . "advanced_stats_new TO " . DB_PREFIX . "advanced_stats, "
        . DB_PREFIX . "advanced_stats_history_new TO " . DB_PREFIX . "advanced_stats_history;";
    $db->query($sql);
} catch (\Throwable $th) {
    error_log('Error: ' . $th->getMessage() . '.');
}

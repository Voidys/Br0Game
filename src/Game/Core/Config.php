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
use App\Game\Core\Config\ConfigFieldProcessor;
use App\Game\Core\Config\ConfigFieldSchema;
use UnexpectedValueException;

/**
 * Settings of one universe as an object: the global keys (table `config`) merged with the universe's keys (table
 * `config_universe`), plus `moduls` (module id => state) and `uni`. Read and write as properties
 * ($config->fleet_speed); unknown keys throw UnexpectedValueException. Changes are collected and written with
 * save() (universe keys), saveGlobalKeys() (global keys) or saveModules(). Instances are created by ConfigRegistry.
 */
class Config
{
    private array $configData = [];
    private array $updateRecords = [];
    private ConfigRegistry $registry;

    /** built by ConfigRegistry (one instance per universe and request) */
    public function __construct(array $configData, ConfigRegistry $registry)
    {
        $this->configData = $configData;
        $this->registry = $registry;
    }

    /**
     * Value of a setting; throws UnexpectedValueException for an unknown key.
     */
    public function __get(string $key): mixed
    {
        if (!isset($this->configData[$key])) {
            throw new UnexpectedValueException(sprintf("Unknown configuration key %s!", $key));
        }

        return $this->configData[$key];
    }

    /**
     * Changes a setting in memory and remembers it for the next save(); unknown keys throw.
     */
    public function __set(string $key, mixed $value): void
    {
        if (!isset($this->configData[$key]) && !array_key_exists($key, $this->configData)) {
            throw new UnexpectedValueException(sprintf("Unknown configuration key %s!", $key));
        }
        $this->updateRecords[]  = $key;
        $this->configData[$key] = $value;
    }

    /**
     * True if the key exists and is not null.
     */
    public function __isset(string $key): bool
    {
        return isset($this->configData[$key]);
    }

    /**
     * Writes the changed universe keys (INSERT ... ON DUPLICATE KEY UPDATE into config_universe); keys that belong to
     * the global schema are skipped here. Clears the list of changes.
     */
    public function save(): bool
    {
        if (empty($this->updateRecords)) {
            // Do nothing here.
            return true;
        }

        $db = $this->registry->database();
        $universe = $this->configData['uni'];
        $uniKeys = array_keys($this->registry->universeSchema()->getAllFields());

        foreach ($this->updateRecords as $key) {
            if (in_array($key, $uniKeys)) {
                $sql = 'INSERT INTO %%CONFIG_UNIVERSE%% (`uni`, `key`, `value`) VALUES (:uni, :key, :value)
                        ON DUPLICATE KEY UPDATE `value` = :value;';
                $db->insert($sql, [
                    ':uni' => $universe,
                    ':key' => $key,
                    ':value' => $this->configData[$key],
                ]);
            }
        }

        $this->updateRecords = [];
        return true;
    }

    /**
     * Writes the changed global keys into `config` (keys of the universe schema are skipped). Clears the list of changes.
     */
    public function saveGlobalKeys(): bool
    {
        if (empty($this->updateRecords)) {
            // Do nothing here.
            return true;
        }

        $db = $this->registry->database();
        $globalKeys = array_keys($this->registry->globalSchema()->getAllFields());

        foreach ($this->updateRecords as $key) {
            if (in_array($key, $globalKeys)) {
                $sql = 'INSERT INTO %%CONFIG%% (`key`, `value`) VALUES (:key, :value)
                        ON DUPLICATE KEY UPDATE `value` = :value;';
                $db->insert($sql, [
                    ':key' => $key,
                    ':value' => $this->configData[$key],
                ]);
            }
        }

        $this->updateRecords = [];
        return true;
    }

    /**
     * Writes the state of all modules of this universe to config_universe_modules.
     */
    public function saveModules(): bool
    {
        foreach ($this->configData['moduls'] as $modulKey => $modulValue) {
            $sql = 'UPDATE %%CONFIG_UNIVERSE_MODULES%% SET `state` = :state 
                WHERE `uni` = :universe AND `module` = :module;';
            $this->registry->database()->update($sql, [
                ':universe' => $this->configData['uni'],
                ':module' => $modulKey,
                ':state' => $modulValue,
            ]);
        }
        return true;
    }
}

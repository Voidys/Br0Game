<?php

namespace App\Game\Core;

use App\Game\Core\Config\ConfigFieldProcessor;
use App\Game\Core\Config\ConfigFieldSchema;
use Exception;

/**
 * The game configuration: one Config per universe (global keys merged with the universe's keys and
 * modules), loaded on first use and shared for the request. Formerly the static side of Config
 * (Config::get(), Config::reload()).
 */
class ConfigRegistry
{
    /** @var array<int, Config> */
    private array $instances = [];
    private ?ConfigFieldSchema $schemaGlobal = null;
    private ?ConfigFieldSchema $schemaUni = null;
    /** @var array<string, string>|null global key/value rows as stored (untransformed), for App\Runtime\GameConfig */
    private ?array $rawGlobal = null;
    /** @var array<int, array<string, string>> per-universe key/value rows as stored (untransformed) */
    private array $rawUniverse = [];

    public function __construct(
        private Database $db,
        private Singleton $state,
    ) {}

    /** true once the instances are built (texts loaded during the build must not ask for the config) */
    public function isLoaded(): bool
    {
        return !empty($this->instances);
    }

    /**
     * Config of a universe; 0 = the current universe (1 when none is selected). The first call loads all
     * configurations from the database.
     * @throws Exception for an unknown universe id.
     */
    public function get(int $universe = 0): Config
    {
        if (empty($this->instances)) {
            $this->schemaGlobal = new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_BASIC, $this->state, $this);
            $this->schemaUni = new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_UNI, $this->state, $this);
            $this->generateInstances();
        }

        if ($universe === 0) {
            $universe = (new Universe($this->state))->current();
        }

        if ($universe === 0) {
            $universe = 1;
        }

        if (!isset($this->instances[$universe])) {
            throw new Exception("Unknown universe id: " . $universe);
        }

        return $this->instances[$universe];
    }

    /**
     * Rereads the configuration from the database (after settings were saved by others).
     */
    public function reload(): void
    {
        $this->generateInstances();
    }

    /**
     * The rows read by the last load, untransformed; null while the configuration is not loaded.
     * App\Runtime\GameConfig reads them instead of querying the same tables again.
     *
     * @return array{0: array<string, string>, 1: array<int, array<string, string>>}|null
     */
    public function rawRows(): ?array
    {
        return $this->rawGlobal === null ? null : [$this->rawGlobal, $this->rawUniverse];
    }

    /** @internal for Config and Language (request data in the game state) */
    public function gameState(): Singleton
    {
        return $this->state;
    }

    /** @internal for Config */
    public function database(): Database
    {
        return $this->db;
    }

    /** @internal for Config */
    public function globalSchema(): ConfigFieldSchema
    {
        return $this->schemaGlobal;
    }

    /** @internal for Config */
    public function universeSchema(): ConfigFieldSchema
    {
        return $this->schemaUni;
    }

    /**
     * Reads `config` and `config_universe` (values converted by the field transforms of ConfigFieldSchema), inserts
     * missing keys with their schema default and missing modules as disabled, merges global and universe keys and
     * creates one Config per universe (registering it with Universe). Also remembers the raw rows for App\Runtime\GameConfig.
     */
    private function generateInstances(): void
    {
        $db = $this->db;

        // Load global config from key-value table
        $globalConfig = [];
        $rawGlobal = [];
        $rawUniverse = [];
        $sql = 'SELECT `key`, `value` FROM %%CONFIG%%;';
        $globalConfigResult = $db->select($sql);
        foreach ($globalConfigResult as $row) {
            $key = $row['key'];
            $value = $row['value'];
            $rawGlobal[$key] = $value;

            $field = $this->schemaGlobal->getField($key);
            if ($field && isset($field['transform'])) {
                $value = ConfigFieldProcessor::applyTypeTransform($value, $field['transform']);
            }
            $globalConfig[$key] = $value;
        }

        // Ensure all expected keys are present in global config
        $expectedKeys = array_keys($this->schemaGlobal->getAllFields());
        $keysMissing = array_diff($expectedKeys, array_keys($globalConfig));
        $sql = 'INSERT INTO %%CONFIG%% (`key`, `value`) VALUES (:key, :value);';
        foreach ($keysMissing as $missingKey) {
            $fieldConfig = $this->schemaGlobal->getField($missingKey);
            $db->insert($sql, [
                ':key' => $missingKey,
                ':value' => $fieldConfig['default'] ?? '',
            ]);
            $globalConfig[$missingKey] = $fieldConfig['default'] ?? '';
            $rawGlobal[$missingKey] = (string) ($fieldConfig['default'] ?? '');
        }

        // Load universe configs from key-value table
        $universeConfigs = [];
        $sql = 'SELECT `uni`, `key`, `value` FROM %%CONFIG_UNIVERSE%% ORDER BY `uni`;';
        $universeResult = $db->select($sql);
        foreach ($universeResult as $row) {
            $uni = $row['uni'];
            $key = $row['key'];
            $value = $row['value'];
            $rawUniverse[$uni][$key] = $value;

            $field = $this->schemaUni->getField($key);
            if ($field && isset($field['transform'])) {
                $value = ConfigFieldProcessor::applyTypeTransform($value, $field['transform']);
            }

            if (!isset($universeConfigs[$uni])) {
                $universeConfigs[$uni] = [];
            }
            $universeConfigs[$uni][$key] = $value;
        }
        
        // Ensure all expected keys are present for each universe config
        $expectedKeys = array_keys($this->schemaUni->getAllFields());
        foreach ($universeConfigs as $uni => $configs) {
            $keysMissing = array_diff($expectedKeys, array_keys($configs));

            $sql = 'INSERT INTO %%CONFIG_UNIVERSE%% (`uni`, `key`, `value`) VALUES (:uni, :key, :value);';
            foreach ($keysMissing as $missingKey) {
                $fieldConfig = $this->schemaUni->getField($missingKey);
                $db->insert($sql, [
                    ':uni' => $uni,
                    ':key' => $missingKey,
                    ':value' => $fieldConfig['default'] ?? '',
                ]);
                $universeConfigs[$uni][$missingKey] = $fieldConfig['default'] ?? '';
                $rawUniverse[$uni][$missingKey] = (string) ($fieldConfig['default'] ?? '');
            }
        }

        $this->rawGlobal = $rawGlobal;
        $this->rawUniverse = $rawUniverse;

        // Load modules for each universe
        foreach ($universeConfigs as $uni => $config) {
            $sql = 'SELECT * FROM %%CONFIG_UNIVERSE_MODULES%% WHERE `uni` = :universe;';
            $modulesResult = $db->select($sql, [':universe' => $uni]);
            $moduls = [];
            foreach ($modulesResult as $modulesRow) {
                $moduls[$modulesRow['module']] = $modulesRow['state'];
            }

            // Ensure all modules are present
            $modules = $this->state->modules;
            foreach ($modules as $moduleKey => $moduleName) {
                if (!isset($moduls[$moduleKey])) {
                    $sql = 'INSERT INTO %%CONFIG_UNIVERSE_MODULES%% (`uni`, `module`, `module_name`, `state`)
                        VALUES (:uni, :module, :module_name, :state)';
                    $db->insert($sql, [
                        ':uni' => $uni,
                        ':module' => $moduleKey,
                        ':module_name' => $moduleName,
                        ':state' => 0,
                    ]);
                    $moduls[$moduleKey] = 0;
                }
            }

            // Merge global config with universe config
            $configData = array_merge($globalConfig, $config);
            $configData['moduls'] = $moduls;
            $configData['uni'] = $uni;

            $this->instances[$uni] = new Config($configData, $this);
            (new Universe($this->state))->add($uni);
        }
    }
}

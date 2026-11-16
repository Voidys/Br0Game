<?php

namespace App\Game\Cronjob;

use App\Game\Bots\BotEngine;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * Ticks the bots of every universe that has bots_enabled. Game code (fleet functions, module
 * checks, unit data) follows the current universe of the process, so the engine switches it per
 * universe and it is restored afterwards for the cronjobs that run after this one.
 */
class BotCronjob extends AbstractCronjobTask
{
    public function __construct(
        Database $db,
        ConfigRegistry $configs,
        Singleton $state,
        private readonly BotEngine $engine,
    ) {
        parent::__construct($db, $configs, $state);
    }

    /**
     * Every minute: for each universe with bots_enabled gives the bot engine its tick budget (bots_ticks_per_run,
     * default 25); the current universe is restored afterwards.
     */
    public function run(): void
    {
        $original = (int) $this->universe()->current();

        try {
            foreach ($this->universe()->availableUniverses() as $universe) {
                $universe = (int) $universe;
                $config = $this->configs->get($universe);
                if (empty($config->bots_enabled)) {
                    continue;
                }

                $this->engine->tick($universe, (int) $config->bots_ticks_per_run ?: 25);
            }
        } finally {
            $this->engine->useUniverse($original);
        }
    }
}

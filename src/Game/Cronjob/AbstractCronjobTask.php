<?php

namespace App\Game\Cronjob;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * Base of the cronjob tasks: database, configuration and game state are injected
 * (tasks are container services, see config/services.yaml).
 */
abstract class AbstractCronjobTask implements CronjobTaskInterface
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected readonly Database $db,
        protected readonly ConfigRegistry $configs,
        protected readonly Singleton $state,
    ) {}
}

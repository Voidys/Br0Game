<?php

namespace App\Controller;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Database, configuration and game state for the game page controllers, injected by the
 * container (setter injection, so the page classes keep their parameterless constructors).
 */
trait GameServices
{
    use \App\Game\Core\GameUtilities;

    protected Database $db;
    protected ConfigRegistry $configs;
    protected Singleton $state;

    #[Required]
    public function setGameServices(Database $db, ConfigRegistry $configs, Singleton $state): void
    {
        $this->db = $db;
        $this->configs = $configs;
        $this->state = $state;
    }

    /**
     * For controllers created with `new` inside game code (error pages, nested pages): take the
     * services of the creating controller.
     */
    public function withGameServices(Database $db, ConfigRegistry $configs, Singleton $state): static
    {
        $this->setGameServices($db, $configs, $state);

        return $this;
    }

    public function withGameServicesOf(object $creator): static
    {
        $this->setGameServices($creator->db, $creator->configs, $creator->state);

        return $this;
    }
}

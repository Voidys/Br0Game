<?php

namespace App\Controller;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Runtime\CanonicalUrl;
use App\Security\AuthRateLimits;
use App\Security\FleetDailyCaps;
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
    protected AuthRateLimits $rateLimits;
    protected CanonicalUrl $canonicalUrl;
    protected FleetDailyCaps $fleetCaps;

    /**
     * Container (setter) injection of database, config registry and game state.
     */
    #[Required]
    public function setGameServices(Database $db, ConfigRegistry $configs, Singleton $state): void
    {
        $this->db = $db;
        $this->configs = $configs;
        $this->state = $state;
    }

    /** Abuse limits (registration, lost password, admin prompt) and e-mail link base URL, for the controllers that need them. */
    #[Required]
    public function setSecurityServices(AuthRateLimits $rateLimits, CanonicalUrl $canonicalUrl): void
    {
        $this->rateLimits = $rateLimits;
        $this->canonicalUrl = $canonicalUrl;
    }

    /** Daily fleet launch and attack caps, for the controllers that send fleets. */
    #[Required]
    public function setFleetCaps(FleetDailyCaps $fleetCaps): void
    {
        $this->fleetCaps = $fleetCaps;
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

    /**
     * Copies the game and security services of the creating controller $creator (for error pages and nested controllers built with `new`).
     */
    public function withGameServicesOf(object $creator): static
    {
        $this->setGameServices($creator->db, $creator->configs, $creator->state);
        $this->setSecurityServices($creator->rateLimits, $creator->canonicalUrl);

        return $this;
    }
}

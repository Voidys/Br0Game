<?php

namespace App\Game\Core;

use App\Game\Model\ElementCatalog;

/**
 * Loads the game's unit data (resource names, prices, requirements, combat caps, production) of the
 * current universe from the vars cache into the game state as the typed ElementCatalog (formerly src/Game/Support/vars.php).
 */
class GameData
{
    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Loads the "vars" cache and builds the typed element catalog of the current universe (universe 0 defaults
     * overridden by the universe's own rows, plus the fixed resource ids 901 metal, 902 crystal, 903 deuterium,
     * 911 energy and the resource lists `ressources` and `resstype`), published as `$state->elementCatalog`. The
     * raw arrays (resource, reslist, pricelist, requeriments, CombatCaps, ProdGrid) are no longer published.
     */
    public function load(): void
    {
        $cache = new Cache($this->db, $this->state);
        $universe = (new Universe($this->state))->current();

        $vars = $cache->getData('vars');

        $this->state->elementCatalog = ElementCatalog::fromVarsData($vars, $universe);
    }
}

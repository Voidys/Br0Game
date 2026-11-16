<?php

namespace App\Game\Core;

/**
 * Loads the game's unit data (resource names, prices, requirements, combat caps, production) of the
 * current universe from the vars cache into the game state (formerly src/Game/Support/vars.php).
 */
class GameData
{
    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Loads the "vars" cache and publishes the game data of the current universe (universe 0 defaults overridden by
     * the universe's own rows) as properties of the game state, i.e. $resource, $reslist, $pricelist, $CombatCaps,
     * $ProdGrid, $requeriments. Adds the fixed resource ids (901 metal, 902 crystal, 903 deuterium, 911 energy) and the
     * resource lists `ressources` and `resstype` (1 = tradable, 2 = energy).
     */
    public function load(): void
    {
        $cache = new Cache($this->db, $this->state);
        $universe = (new Universe($this->state))->current();

        $vars = $cache->getData('vars');
        $result = $vars[0];

        if (isset($vars[$universe])) {
            $result = array_replace_recursive($result, $vars[$universe]);
        }
        foreach ($result as $k => $v) {
            $this->state->$k = $v;
        }

        $this->state->resource[901] = 'metal';
        $this->state->resource[902] = 'crystal';
        $this->state->resource[903] = 'deuterium';
        $this->state->resource[911] = 'energy';

        $this->state->reslist['ressources']  = [901, 902, 903, 911];
        $this->state->reslist['resstype'][1] = [901, 902, 903];
        $this->state->reslist['resstype'][2] = [911];
        $this->state->reslist['resstype'][3] = [];

        // the typed element catalog (GameUtilities::elements()) is built lazily from the data just loaded
        $this->state->elementCatalog = null;
    }
}

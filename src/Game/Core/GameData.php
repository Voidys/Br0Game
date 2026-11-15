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
            $$k = & $this->state->$k;
        }

        $resource[901] = 'metal';
        $resource[902] = 'crystal';
        $resource[903] = 'deuterium';
        $resource[911] = 'energy';

        $reslist['ressources']  = [901, 902, 903, 911];
        $reslist['resstype'][1] = [901, 902, 903];
        $reslist['resstype'][2] = [911];
        $reslist['resstype'][3] = [];
    }
}

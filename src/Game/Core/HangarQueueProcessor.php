<?php

namespace App\Game\Core;

/**
 * Shipyard queue of a planet: turns the elapsed build time into finished ships.
 *
 * Collaborators: BuildFunctions (build time per ship), QueueState (planet, built deltas). Queue format: StoredData
 * JSON list of [elementId, count] in planets.b_hangar_id, elapsed time in planets.b_hangar.
 */
class HangarQueueProcessor
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Finishes the part of the shipyard queue that fits into the elapsed build time (b_hangar): completed ships are
     * added to the planet and to the built deltas, the rest stays queued. Elements with a build time of 0 complete at once.
     *
     * @return bool false when the queue was empty
     */
    public function process(QueueState $st)
    {
        $resource = $this->state->resource;

        $BuildQueue = !empty($st->planet['b_hangar_id']) ? StoredData::decode($st->planet['b_hangar_id']) : [];
        if (!$BuildQueue) {
            $st->planet['b_hangar'] = 0;
            $st->planet['b_hangar_id'] = '';
            return false;
        }

        $st->planet['b_hangar']   += ($st->time - $st->planet['last_update']);
        $BuildArray = [];
        $userModel = $this->models()->user($st->user);
        $planetModel = $this->models()->planet($st->planet);
        foreach ($BuildQueue as $Item) {
            $AcumTime = $this->buildFunctions()->buildingTimeFor($userModel, $planetModel, $Item[0]);
            $BuildArray[] = [$Item[0], $Item[1], $AcumTime];
        }

        $NewQueue = [];
        $Done = false;
        foreach ($BuildArray as $Item) {
            $Element = $Item[0];
            $Count = $Item[1];

            if ($Done == false) {
                $BuildTime = $Item[2];
                $Element = (int) $Element;
                if ($BuildTime == 0) {
                    if (!isset($st->builded[$Element])) {
                        $st->builded[$Element] = 0;
                    }

                    $st->builded[$Element]            += $Count;
                    $st->planet[$resource[$Element]]  += $Count;
                    continue;
                }

                $Build = max(min(floor($st->planet['b_hangar'] / $BuildTime), $Count), 0);

                if ($Build == 0) {
                    $NewQueue[] = [$Element, $Count];
                    $Done = true;
                    continue;
                }

                if (!isset($st->builded[$Element])) {
                    $st->builded[$Element] = 0;
                }

                $st->builded[$Element]            += $Build;
                $st->planet['b_hangar']           -= $Build * $BuildTime;
                $st->planet[$resource[$Element]]  += $Build;
                $Count                              -= $Build;

                if ($Count == 0) {
                    continue;
                } else {
                    $Done = true;
                }
            }
            $NewQueue[] = [$Element, $Count];
        }

        if (empty($NewQueue)) {
            $st->planet['b_hangar_id'] = '';
            $st->planet['b_hangar'] = 0;
        } else {
            $st->planet['b_hangar_id'] = StoredData::encode($NewQueue);
        }

        return true;
    }
}

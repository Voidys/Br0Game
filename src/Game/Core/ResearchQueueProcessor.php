<?php

namespace App\Game\Core;

/**
 * Research queue of a user (b_tech, b_tech_id, b_tech_planet, b_tech_queue as StoredData JSON): finishes the
 * running research when its end time has passed and starts the next affordable one, paying from the planet the
 * research was queued on.
 *
 * Collaborators: BuildFunctions, ResourceProduction (research network level), ResourcePersistence (charging another
 * planet), QueueFailureNotifier, ResourceUpdate (nested runs for the user's other planets), QueueState.
 */
class ResearchQueueProcessor
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Finishes the user's current research when its end time has passed: applies the new level (updating all other
     * planets first for techs that change production: energy, plasma, ...) and pops it from b_tech_queue.
     *
     * @return bool true when more researches are queued (caller starts the next one)
     */
    public function finishCurrent(QueueState $st)
    {
        $elements = $this->elements();

        if (empty($st->user['b_tech_id']) || $st->user['b_tech'] > $st->time) {
            return false;
        }

        if (!isset($st->builded[$st->user['b_tech_id']])) {
            $st->builded[$st->user['b_tech_id']] = 0;
        }

        $CurrentQueue = !empty($st->user['b_tech_queue']) ? StoredData::decode($st->user['b_tech_queue']) : [];

        $Element = $st->user['b_tech_id'];
        $BuildLevel = $CurrentQueue[0][1];
        $BuildEndTime = $st->user['b_tech'];

        $hashTechs = [113, 131, 132, 133];
        if (in_array($Element, $hashTechs)) {
            $sql = 'SELECT * FROM %%PLANETS%% WHERE id_owner = :userId AND id <> :planetId FOR UPDATE;';
            $PLANETS = $this->db->select($sql, [
                ':userId' => $st->user['id'],
                ':planetId' => $st->planet['id'],
            ]);
            foreach ($PLANETS as $planetId => $cplanet) {
                $ressUpdate = new ResourceUpdate($this->db, $this->configs, $this->state, true, false);
                $ressUpdate->CalcResource($st->user, $cplanet, true, $BuildEndTime, false);
            }
            ($st->updateResource)($BuildEndTime, false);
        } elseif (count($CurrentQueue) > 1) {
            // Bring the planet up to the end time so the next queued tech is paid from current resources
            ($st->updateResource)($BuildEndTime, false);
        }

        $st->builded[$Element] = $BuildLevel;
        $st->user[$elements->column($Element)] = $BuildLevel;

        array_shift($CurrentQueue);

        if (count($CurrentQueue) == 0) {
            $st->user['b_tech'] = 0;
            $st->user['b_tech_id'] = 0;
            $st->user['b_tech_planet'] = 0;
            $st->user['b_tech_queue'] = '';
            return false;
        } else {
            $st->user['b_tech_id'] = 0;
            $st->user['b_tech_queue'] = StoredData::encode(array_values($CurrentQueue));
            return true;
        }
    }

    /**
     * Starts the first research of the queue: pays it from the planet it was queued on (loaded and brought up to date
     * when it is another planet), sets b_tech/b_tech_id/b_tech_planet/b_tech_queue. Unaffordable researches are dropped
     * (with a message when users.hof is 1) and the next one is tried, with the remaining queue re-timed.
     *
     * @param bool $save write the result to the DB afterwards
     */
    public function startNext(QueueState $st)
    {
        $elements = $this->elements();
        $notifier = $this->gameUtility(QueueFailureNotifier::class);
        $LNG = $notifier->language($st->user['id']);
        if (empty($st->user['b_tech_queue'])) {
            $this->resetTechQueue($st);
            return false;
        }

        $CurrentQueue = !empty($st->user['b_tech_queue']) ? StoredData::decode($st->user['b_tech_queue']) : [];
        $Loop = true;
        while ($Loop == true) {
            $ListIDArray = $CurrentQueue[0];
            $isAnotherPlanet = $ListIDArray[4] != $st->planet['id'];
            $PLANET = $isAnotherPlanet ? $this->loadOtherPlanet($st, $ListIDArray[4]) : $st->planet;

            $userModel = $this->models()->user($st->user);
            $PLANET[$elements->column(31) . '_inter'] = $this->gameUtility(ResourceProduction::class)->networkLevelFor($userModel, $this->models()->planet($PLANET));
            $planetModel = $this->models()->planet($PLANET);

            $Element = $ListIDArray[0];
            $Level = $ListIDArray[1];
            $costResources = $this->buildFunctions()->elementPriceFor($userModel, $planetModel, $Element, false, $Level);
            $BuildTime = $this->buildFunctions()->buildingTimeFor($userModel, $planetModel, $Element, $costResources);
            $HaveResources = $this->buildFunctions()->isBuyableFor($userModel, $planetModel, $Element, $costResources);
            $BuildEndTime = $st->user['b_tech'] + $BuildTime;
            $CurrentQueue[0] = [$Element, $Level, $BuildTime, $BuildEndTime, $PLANET['id'], $costResources];

            if ($HaveResources == true) {
                $this->payResearch($st, $PLANET, $costResources, $isAnotherPlanet);

                if ($isAnotherPlanet) {
                    $this->gameUtility(ResourcePersistence::class)->removeResources($ListIDArray[4], $costResources);
                }

                $st->user['b_tech_id'] = $Element;
                $st->user['b_tech'] = $BuildEndTime;
                $st->user['b_tech_planet'] = $PLANET['id'];
                $st->user['b_tech_queue'] = StoredData::encode($CurrentQueue);

                $Loop = false;
            } else {
                if ($st->user['hof'] == 1) {
                    $Message = $notifier->notEnoughResources($LNG, $PLANET, $Element, $costResources);
                    $notifier->send($LNG, $st->user['id'], 'sys_techlist', $Message, $st->time);
                }

                array_shift($CurrentQueue);

                if (count($CurrentQueue) == 0) {
                    $this->resetTechQueue($st);

                    $Loop = false;
                } else {
                    $CurrentQueue = $this->retimeQueue($st, $PLANET, $CurrentQueue, $BuildEndTime - $BuildTime);
                }
            }

            if (!$isAnotherPlanet) {
                $st->planet = $PLANET;
            }
        }

        return true;
    }

    /**
     * Clears the user's research queue fields (empty queue).
     */
    private function resetTechQueue(QueueState $st): void
    {
        $st->user['b_tech'] = 0;
        $st->user['b_tech_id'] = 0;
        $st->user['b_tech_planet'] = 0;
        $st->user['b_tech_queue'] = '';
    }

    /**
     * Deducts the cost of a started research from $PLANET (by reference). The built deltas only change when the
     * research is paid from the working planet; other planets are charged in the DB by the caller.
     *
     * @param array<string, mixed> $PLANET
     * @param array<int, float|int> $costResources
     */
    private function payResearch(QueueState $st, array &$PLANET, array $costResources, bool $isAnotherPlanet): void
    {
        $elements = $this->elements();

        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $resourceId) {
            if (isset($costResources[$resourceId])) {
                if (!$isAnotherPlanet) {
                    $st->builded[$resourceId] -= $costResources[$resourceId];
                }
                $PLANET[$elements->column($resourceId)] -= $costResources[$resourceId];
            }
        }
    }

    /**
     * Loads (and row-locks) the planet a queued research is paid from and brings it up to the end time of the running
     * research, saving it (nested ResourceUpdate without queue processing of researches).
     *
     * @return array<string, mixed> the updated planet row
     */
    private function loadOtherPlanet(QueueState $st, int|string $planetId): array
    {
        $sql = 'SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;';
        $PLANET = $this->db->selectSingle($sql, [
            ':planetId' => $planetId,
        ]);

        $RPLANET = new ResourceUpdate($this->db, $this->configs, $this->state, true, false);
        [, $PLANET] = $RPLANET->CalcResource($st->user, $PLANET, true, $st->user['b_tech']);

        return $PLANET;
    }

    /**
     * Recomputes duration and end time of the remaining research queue entries, chained from $baseTime.
     *
     * @param array<string, mixed> $PLANET planet the durations are computed for
     * @param array<int, array> $queue
     * @return array<int, array>
     */
    private function retimeQueue(QueueState $st, array $PLANET, array $queue, int|float $baseTime): array
    {
        $NewQueue = [];
        $userModel = $this->models()->user($st->user);
        $planetModel = $this->models()->planet($PLANET);
        foreach ($queue as $ListIDArray) {
            $ListIDArray[2] = $this->buildFunctions()->buildingTimeFor($userModel, $planetModel, $ListIDArray[0], null, false, $ListIDArray[1]);
            $baseTime += $ListIDArray[2];
            $ListIDArray[3] = $baseTime;
            $NewQueue[] = $ListIDArray;
        }

        return $NewQueue;
    }
}

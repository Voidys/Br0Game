<?php

namespace App\Game\Core;

use InvalidArgumentException;

/**
 * Attack combat system (ACS) group time handling: remaining time until the group's arrival and shifting a whole group
 * (aks row, flights, events and fleet log) by a time difference when a new fleet joins.
 *
 * Collaborators: Database (AKS, FLEETS, FLEETS_EVENT, LOG_FLEETS), Singleton (request time).
 */
class AcsService
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Seconds until the ACS group arrives (0 for no/unknown group).
     */
    public function getACSDuration(int $acsId): int
    {
        if (empty($acsId)) {
            return 0;
        }

        $sql = 'SELECT ankunft FROM %%AKS%% WHERE id = :acsId;';
        $acsEndTime = $this->db->selectSingle($sql, [
            ':acsId' => $acsId,
        ], 'ankunft');

        return empty($acsEndTime) ? 0 : floor($acsEndTime - $this->state->runtime->timestamp);
    }

    /**
     * Postpones a whole ACS group by $timeDifference seconds: the group's arrival, every flight's arrival/stay/return
     * time and event time, and the fleet log copies.
     *
     * @throws InvalidArgumentException for an empty group id
     */
    public function setACSTime(int $timeDifference, int $acsId): bool
    {
        if (empty($acsId)) {
            throw new InvalidArgumentException('Missing acsId on ' . FleetFunctions::class . '::setACSTime');
        }

        $db = $this->db;

        $sql = 'UPDATE %%AKS%% SET ankunft = ankunft + :time WHERE id = :acsId;';
        $db->update($sql, [
            ':time' => $timeDifference,
            ':acsId' => $acsId,
        ]);

        $sql = 'UPDATE %%FLEETS%%, %%FLEETS_EVENT%% SET
            `arrival_time` = arrival_time + :time,
            `stay_time`   = stay_time + :time,
            `return_time` = return_time + :time,
            `time`        = time + :time
            WHERE `group` = :acsId AND `id` = fleetID;';

        $db->update($sql, [
            ':time'     => $timeDifference,
            ':acsId'    => $acsId,
        ]);

        $sql = 'SELECT `id`, `arrival_time`, `stay_time`, `return_time`
            FROM %%LOG_FLEETS%%
            WHERE `group` = :acsId;';
        $logFleets = $db->select($sql, [
            ':acsId' => $acsId,
        ]);

        $sql = 'UPDATE %%LOG_FLEETS%% SET
            `arrival_time`           = :arrival_time,
            `arrival_time_formated`  = :arrival_time_formated,
            `stay_time`             = :stay_time,
            `stay_time_formated`    = :stay_time_formated,
            `return_time`           = :return_time,
            `return_time_formated`  = :return_time_formated
            WHERE `id` = :id;';
        foreach ($logFleets as $logFleet) {
            $db->update($sql, [
                ':arrival_time'          => $logFleet['arrival_time'] + $timeDifference,
                ':arrival_time_formated' => $this->db->formatDate($logFleet['arrival_time'] + $timeDifference),
                ':stay_time'            => $logFleet['stay_time'] + $timeDifference,
                ':stay_time_formated'   => $this->db->formatDate($logFleet['stay_time'] + $timeDifference),
                ':return_time'          => $logFleet['return_time'] + $timeDifference,
                ':return_time_formated' => $this->db->formatDate($logFleet['return_time'] + $timeDifference),
                ':id'                   => $logFleet['id'],
            ]);
        }

        return true;
    }
}

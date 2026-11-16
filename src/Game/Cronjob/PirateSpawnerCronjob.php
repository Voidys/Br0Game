<?php

namespace App\Game\Cronjob;

use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\Discord;
use App\Game\Core\FleetFunctions;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

class PirateSpawnerCronjob extends AbstractCronjobTask
{

    public function __construct(
        \App\Game\Core\Database $db,
        \App\Game\Core\ConfigRegistry $configs,
        \App\Game\Core\Singleton $state,
    ) {
        parent::__construct($db, $configs, $state);
        require_once 'config/game.php';
    }

    public function run(): void
    {
        try {
            $this->db->insert("INSERT INTO %%PIRATE_BASES_HISTORY%% SELECT *, :timestamp FROM %%PIRATE_BASES%%;", [
                ':timestamp' => $this->state->runtime->timestamp,
            ]);
            $this->db->nativeQuery("DELETE FROM %%PIRATE_BASES%%;");

            $excludedShips = [
                SHIP_PROBE, // Spy Probe
                SHIP_SOLSAT, // Solar Satellite
                VOID_TITAN, // Void Titan
            ];

            $universes = $this->universe()->availableUniverses();
            foreach ($universes as $universe) {
                if (!$this->viewHelpers()->isModuleAvailable(MODULE_PIRATE_BASES, $universe)) {
                    continue;
                }
                $config = $this->configs->get($universe);

                $users = $this->getUsers($universe, $config->piratebase_min_points);

                foreach ($users as $user) {
                    $planets = $this->getUserPlanets($user['id']);
                    $userShips = $this->getUserShips($user['id'], $planets);

                    $index = random_int(0, count($planets) - 1);
                    $targetPlanet = $planets[$index];
                    $pirateFleet = $this->getPirateFleet($userShips, $excludedShips, $config->piratebase_fleet_percentage_min, $config->piratebase_fleet_percentage_max);
                    $ress = $this->getPirateRess($planets, $user['id_planet'], $config->piratebase_mainplanet_only, $config->piratebase_ress_hours);

                    $this->createPirateBase($targetPlanet, $pirateFleet, $ress, $user);
                }
            }
        } catch (\Exception $e) {
            error_log("Error in PirateSpawnerCronjob: " . $e->getMessage());
            $this->discord()->sendLog('PirateSpawnerCronjob', null, $e);
        }
    }

    private function createPirateBase(array $targetPlanet, array $pirateFleet, array $ress, array $user): void
    {
        $sql = "INSERT INTO %%PIRATE_BASES%% SET
            `id_owner`      = :idOwner,
            `universe`      = :universe,
            `galaxy`        = :galaxy,
            `system`        = :system,
            `metal`         = :metal,
            `crystal`       = :crystal,
            `deuterium`     = :deuterium,
            `ships`         = :fleetArray,
            `der_metal`     = :derMetal,
            `der_crystal`   = :derCrystal;";
        $this->db->insert($sql, [
            ':idOwner'      => $user['id'],
            ':universe'     => $targetPlanet['universe'],
            ':galaxy'       => $targetPlanet['galaxy'],
            ':system'       => $targetPlanet['system'],
            ':metal'        => $ress[RESOURCE_METAL],
            ':crystal'      => $ress[RESOURCE_CRYSTAL],
            ':deuterium'    => $ress[RESOURCE_DEUT],
            ':fleetArray'   => $this->fleetFunctions()->serialize($pirateFleet),
            ':derMetal'     => 0,
            ':derCrystal'   => 0,
        ]);
    }

    private function getPirateRess(array $planets, int $mainPlanetId, bool $mainPlanetOnly, int $hours): array
    {
        $ress = [
            RESOURCE_METAL => 0,
            RESOURCE_CRYSTAL => 0,
            RESOURCE_DEUT => 0,
        ];
        foreach ($planets as $planet) {
            if ($mainPlanetOnly && $planet['id'] != $mainPlanetId) {
                continue;
            }

            $ress[RESOURCE_METAL] += $planet['metal_perhour'] * $hours;
            $ress[RESOURCE_CRYSTAL] += $planet['crystal_perhour'] * $hours;
            $ress[RESOURCE_DEUT] += $planet['deuterium_perhour'] * $hours;
        }
        return $ress;
    }

    private function getPirateFleet(array $userShips, array $excludedShips, int $minPercentage, int $maxPercentage): array
    {
        $pirateFleet = [];
        foreach ($userShips as $elementId => $amount) {
            if (in_array($elementId, $excludedShips)) {
                continue;
            }
            $percentage = random_int($minPercentage, $maxPercentage);
            $spawnAmount = max(1, floor($amount * ($percentage / 100)));
            $pirateFleet[$elementId] = $spawnAmount;
        }
        return $pirateFleet;
    }

    private function getUserShips(int $userId, array $planets): array
    {
        $resource = $this->state->resource;
        $reslist = $this->state->reslist;

        $sql = "SELECT `ships` FROM %%FLEETS%% WHERE `owner` = :userId;";
        $fleets = $this->db->select($sql, [
            ':userId' => $userId,
        ]);

        $userShips = [];
        foreach ($reslist['fleet'] as $elementId) {
            if (!isset($userShips[$elementId])) {
                $userShips[$elementId] = 0;
            }
            foreach ($planets as $planet) {
                $userShips[$elementId] += $planet[$resource[$elementId]];
            }
        }
        foreach ($fleets as $fleet) {
            $fleetArray = $this->fleetFunctions()->unserialize($fleet['ships']);
            foreach ($fleetArray as $elementId => $amount) {
                $userShips[$elementId] += $amount;
            }
        }

        $userShips = array_filter($userShips);
        return $userShips;
    }

    private function getUserPlanets(int $userId): array
    {
        $sql = "SELECT * FROM %%PLANETS%% WHERE `id_owner` = :userId AND destruyed = 0;";
        return $this->db->select($sql, [
            ':userId' => $userId,
        ]);
    }

    private function getUsers(int $universe, int $minPoints): array
    {
        $sql = "SELECT u.id, u.id_planet, us.lang FROM %%USERS%% u
            JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = 1
            JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id
            WHERE u.onlinetime > :time AND u.universe = :universe AND s.total_points >= :minPoints;";
        return $this->db->select($sql, [
            ':time'      => $this->state->runtime->timestamp - INACTIVE,
            ':universe'  => $universe,
            ':minPoints' => $minPoints,
        ]);
    }
}

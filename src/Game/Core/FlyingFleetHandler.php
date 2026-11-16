<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Core;

use App\Game\Missions\MissionCaseACS;
use App\Game\Missions\MissionCaseAttack;
use App\Game\Missions\MissionCaseColonisation;
use App\Game\Missions\MissionCaseDestruction;
use App\Game\Missions\MissionCaseExpedition;
use App\Game\Missions\MissionCaseMIP;
use App\Game\Missions\MissionCasePiratebaseAttack;
use App\Game\Missions\MissionCasePiratebaseRecycling;
use App\Game\Missions\MissionCasePiratebaseSpy;
use App\Game\Missions\MissionCaseRecycling;
use App\Game\Missions\MissionCaseSpy;
use App\Game\Missions\MissionCaseStay;
use App\Game\Missions\MissionCaseStayAlly;
use App\Game\Missions\MissionCaseTrade;
use App\Game\Missions\MissionCaseTransfer;
use App\Game\Missions\MissionCaseTransport;
use App\Game\Missions\MissionInterface;

/**
 * Processes the due fleet events: for every fleet whose `fleets_event` row carries this run's lock token (set by
 * the caller/cronjob) it instantiates the mission class of the fleet's mission id (missionClasses()) and calls
 * TargetEvent / ReturnEvent / EndStayEvent depending on the fleet status, oldest event first. Fleets of an unknown
 * mission are deleted. Every event runs in its own transaction (see run()); the caller only claims the events.
 */
class FlyingFleetHandler
{
    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
        private ErrorReporter $errors,
    ) {}

    /** Seconds a failed event is parked before it is claimed again. */
    public const QUARANTINE_SECONDS = 300;

    protected string $token;

    /**
     * Mission id => mission class. A method (not a static property) because the MISSION_* constants
     * only exist after the game bootstrap, while the container loads this class earlier.
     *
     * @return array<int, class-string>
     */
    public static function missionClasses(): array
    {
        return [
        MISSION_ATTACK                  => MissionCaseAttack::class,
        MISSION_ACS                     => MissionCaseACS::class,
        MISSION_TRANSPORT               => MissionCaseTransport::class,
        MISSION_STATION                 => MissionCaseStay::class,
        MISSION_HOLD                    => MissionCaseStayAlly::class,
        MISSION_SPY                     => MissionCaseSpy::class,
        MISSION_COLONISATION            => MissionCaseColonisation::class,
        MISSION_RECYCLING               => MissionCaseRecycling::class,
        MISSION_DESTRUCTION             => MissionCaseDestruction::class,
        MISSION_MISSILE                 => MissionCaseMIP::class,
        MISSION_EXPEDITION              => MissionCaseExpedition::class,
        MISSION_TRADE                   => MissionCaseTrade::class,
        MISSION_TRANSFER                => MissionCaseTransfer::class,
        MISSION_PIRATEBASE_ATTACK       => MissionCasePiratebaseAttack::class,
        MISSION_PIRATEBASE_SPY          => MissionCasePiratebaseSpy::class,
        MISSION_PIRATEBASE_RECYCLING    => MissionCasePiratebaseRecycling::class,
        ];
    }

    public function setToken(string $token): void
    {
        $this->token    = $token;
    }

    /**
     * Handles all events locked with the token (see setToken()), ordered by event time then fleet id. Every event runs in
     * its own transaction (Database::transactional, deadlocks are repeated) and releases its lock when it is done, so a
     * failing event is rolled back, reported (game log + Discord) and quarantined (lock released, event time pushed back
     * by QUARANTINE_SECONDS) while the others go on. The fleet rows are read once up front, as before: an event
     * sees its fleet as it was at the claim, even when an earlier event of the batch (e.g. an ACS battle) changed it.
     */
    public function run(): void
    {
        $fleetResult = $this->db->select(
            'SELECT f.*
            FROM %%FLEETS_EVENT%% fe
            INNER JOIN %%FLEETS%% f ON fe.`fleetID` = f.`id`
            WHERE fe.`lock` = :token ORDER BY fe.`time` ASC, fe.`fleetID` ASC;',
            [':token' => $this->token],
        );

        foreach ($fleetResult as $fleetRow) {
            try {
                $this->db->transactional(fn() => $this->runEvent($fleetRow));
            } catch (\Throwable $exception) {
                $this->quarantine($fleetRow, $exception);
            }
        }
    }

    /**
     * Runs one event in the open transaction: locks the event row (skips it when an earlier event of the batch removed the
     * fleet), calls the mission and releases the event lock.
     *
     * @param array<string, mixed> $fleetRow
     */
    private function runEvent(array $fleetRow): void
    {
        $db = $this->db;
        $locked = $db->selectSingle(
            'SELECT `fleetID` FROM %%FLEETS_EVENT%% WHERE `fleetID` = :fleetId AND `lock` = :token FOR UPDATE;',
            [':fleetId' => $fleetRow['id'], ':token' => $this->token],
        );
        if (empty($locked)) {
            return;
        }

        $missionName = $this->missionClass((int) $fleetRow['mission']);
        if ($missionName === null) {
            $db->delete('DELETE FROM %%FLEETS%% WHERE `id` = :fleetId;', [':fleetId' => $fleetRow['id']]);
        } else {
            /** @var MissionInterface $missionObj */
            $missionObj = new $missionName($fleetRow, $this->db, $this->configs, $this->state);

            switch ($fleetRow['status']) {
                case FLEET_OUTWARD:
                    $missionObj->TargetEvent();
                    break;
                case FLEET_RETURN:
                    $missionObj->ReturnEvent();
                    break;
                case FLEET_HOLD:
                    $missionObj->EndStayEvent();
                    break;
            }
        }

        $db->update('UPDATE %%FLEETS_EVENT%% SET `lock` = NULL WHERE `fleetID` = :fleetId AND `lock` = :token;', [
            ':fleetId' => $fleetRow['id'],
            ':token' => $this->token,
        ]);
    }

    /** Mission class of a mission id, null for an unknown mission (its fleet is deleted). */
    protected function missionClass(int $mission): ?string
    {
        return self::missionClasses()[$mission] ?? null;
    }

    /**
     * Parks a failed event: reports the exception, releases the lock and pushes the event time back so the same event
     * cannot stall every later batch.
     *
     * @param array<string, mixed> $fleetRow
     */
    private function quarantine(array $fleetRow, \Throwable $exception): void
    {
        $this->errors->reportIsolated($exception, 'fleet ' . $fleetRow['id'] . ', mission ' . $fleetRow['mission'] . ', status ' . $fleetRow['status']);

        try {
            $this->db->update(
                'UPDATE %%FLEETS_EVENT%% SET `lock` = NULL, `time` = :time WHERE `fleetID` = :fleetId AND `lock` = :token;',
                [
                    ':time' => $this->state->runtime->timestamp + self::QUARANTINE_SECONDS,
                    ':fleetId' => $fleetRow['id'],
                    ':token' => $this->token,
                ],
            );
        } catch (\Throwable $releaseFailure) {
            $this->errors->reportIsolated($releaseFailure, 'quarantine of fleet ' . $fleetRow['id']);
        }
    }
}

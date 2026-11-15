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

namespace App\Game\Missions;

use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\Language;
use App\Game\Core\PlayerUtil;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;

class MissionFunctions
{
    use \App\Game\Core\GameUtilities;

    public $kill = 0;
    public $_fleet = [];
    public $_upd = [];
    public $_updLog = [];
    public $eventTime = 0;
    protected $statsHandler;

    protected function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {
        $this->statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
    }

    protected function UpdateFleet($Option, $Value)
    {
        $this->_fleet[$Option] = $Value;
        $this->_upd[$Option] = $Value;
    }

    protected function UpdateFleetLog($Option, $Value)
    {
        $this->_updLog[$Option] = $Value;
    }

    protected function setState($Value)
    {
        $this->_fleet['status'] = $Value;
        $this->_upd['status'] = $Value;

        switch ($Value) {
            case FLEET_OUTWARD:
                $this->eventTime = $this->_fleet['arrival_time'];
                break;
            case FLEET_RETURN:
                $this->eventTime = $this->_fleet['return_time'];
                break;
            case FLEET_HOLD:
                $this->eventTime = $this->_fleet['stay_time'];
                break;
        }
    }

    protected function SaveFleet()
    {
        $targetMission = $this->_fleet['mission'];
        if ($targetMission != MISSION_COLONISATION && $targetMission != MISSION_EXPEDITION && $targetMission != MISSION_TRADE) {
            // set points of fleet and target owner during arrival (SaveFleet is always called on TargetEvent)
            if ($this->_fleet['group'] != 0) {
                $sql = 'UPDATE %%LOG_FLEETS%% l
                    LEFT JOIN %%STATPOINTS%% owner_stats ON owner_stats.`id_owner` = l.`owner` AND owner_stats.`stat_type` = 1
                    LEFT JOIN %%STATPOINTS%% target_stats ON target_stats.`id_owner` = l.`target_owner` AND target_stats.`stat_type` = 1
                    SET l.`owner_points` = COALESCE(owner_stats.`total_points`, 0), l.`target_owner_points` = COALESCE(target_stats.`total_points`, 0)
                    WHERE l.`group` = :acsId;';
                $this->db->update($sql, [
                    ':acsId' => $this->_fleet['group'],
                ]);
            } else {
                $sql = 'UPDATE %%LOG_FLEETS%% l
                    LEFT JOIN %%STATPOINTS%% owner_stats ON owner_stats.`id_owner` = l.`owner` AND owner_stats.`stat_type` = 1
                    LEFT JOIN %%STATPOINTS%% target_stats ON target_stats.`id_owner` = l.`target_owner` AND target_stats.`stat_type` = 1
                    SET l.`owner_points` = COALESCE(owner_stats.`total_points`, 0), l.`target_owner_points` = COALESCE(target_stats.`total_points`, 0)
                    WHERE `id` = :fleetId;';
                $this->db->update($sql, [
                    ':fleetId' => $this->_fleet['id'],
                ]);
            }
        }

        if ($this->kill == 1) {
            return;
        }

        $param = [];
        $updateQuery = [];

        foreach ($this->_upd as $Opt => $Val) {
            $updateQuery[] = "`" . $Opt . "` = :" . $Opt;
            $param[':' . $Opt] = $Val;
        }

        if (!empty($updateQuery)) {
            $sql = 'UPDATE %%FLEETS%% SET ' . implode(', ', $updateQuery) . ' WHERE `id` = :fleetId;';
            $param[':fleetId'] = $this->_fleet['id'];
            $this->db->update($sql, $param);

            $sql = 'UPDATE %%FLEETS_EVENT%% SET `time` = :time WHERE `fleetID` = :fleetId;';
            $this->db->update($sql, [
                ':time'     => $this->eventTime,
                ':fleetId'  => $this->_fleet['id']
            ]);
        }

        $param = [];
        $updateQuery = [];

        foreach ($this->_updLog as $Opt => $Val) {
            $updateQuery[] = "`" . $Opt . "` = :" . $Opt;
            $param[':' . $Opt] = $Val;
        }

        if (!empty($updateQuery)) {
            $sql = 'UPDATE %%LOG_FLEETS%% SET ' . implode(', ', $updateQuery) . ' WHERE `id` = :fleetId;';
            $param[':fleetId'] = $this->_fleet['id'];
            $this->db->update($sql, $param);
        }
    }

    protected function UpdatePlanet($userId, $planetId, $time = null)
    {
        $sql = 'SELECT u.*, us.* FROM %%USERS%% u 
            INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = u.`id`
            WHERE u.`id` = :userId FOR UPDATE;';
        $targetUser = $this->db->selectSingle($sql, [
            ':userId'   => $userId
        ]);
        $sql = "SELECT * FROM %%PLANETS%% WHERE `id` = :planetId FOR UPDATE;";
        $planet = $this->db->selectSingle($sql, [
            ':planetId' => $planetId,
        ]);

        $planetUpdater = new ResourceUpdate($this->db, $this->configs, $this->state);
        $planetUpdater->CalcResource($targetUser, $planet, true, $time);
    }

    protected function RestoreFleet($onStart = true)
    {
        $resource = & $this->state->resource;

        $fleetData = $this->fleetFunctions()->unserialize($this->_fleet['ships']);

        $userId = $onStart == true ? $this->_fleet['owner'] : $this->_fleet['target_owner'];
        $planetId = $onStart == true ? $this->_fleet['start_id'] : $this->_fleet['end_id'];
        $time = $onStart == true ? $this->_fleet['return_time'] : $this->_fleet['arrival_time'];

        $this->UpdatePlanet($userId, $planetId, $time);

        $updateQuery = [];

        $param = [
            ':metal'        => $this->_fleet['resource_metal'],
            ':crystal'      => $this->_fleet['resource_crystal'],
            ':deuterium'    => $this->_fleet['resource_deuterium'],
            ':planetId'     => $planetId
        ];

        foreach ($fleetData as $shipId => $shipAmount) {
            $updateQuery[] = "p.`" . $resource[$shipId] . "` = p.`" . $resource[$shipId]
                . "` + :" . $resource[$shipId];
            $param[':' . $resource[$shipId]] = $shipAmount;
        }

        $sql = 'UPDATE %%PLANETS%% as p, %%USERS%% as u SET
            ' . implode(', ', $updateQuery) . ',
            p.`metal` = p.`metal` + :metal,
            p.`crystal` = p.`crystal` + :crystal,
            p.`deuterium` = p.`deuterium` + :deuterium
            WHERE p.`id` = :planetId AND u.`id` = p.`id_owner`;';

        $this->db->update($sql, $param);

        $this->KillFleet();
    }

    protected function StoreGoodsToPlanet($onStart = false)
    {
        $sql = 'UPDATE %%PLANETS%% as p, %%USERS%% as u SET
            `metal` = `metal` + :metal,
            `crystal` = `crystal` + :crystal,
            `deuterium` = `deuterium` + :deuterium
            WHERE p.`id` = :planetId AND u.`id` = p.`id_owner`;';

        $this->db->update($sql, [
            ':metal'        => $this->_fleet['resource_metal'],
            ':crystal'      => $this->_fleet['resource_crystal'],
            ':deuterium'    => $this->_fleet['resource_deuterium'],
            ':planetId'     => ($onStart == true ? $this->_fleet['start_id'] : $this->_fleet['end_id'])
        ]);

        $this->UpdateFleet('resource_metal', '0');
        $this->UpdateFleet('resource_crystal', '0');
        $this->UpdateFleet('resource_deuterium', '0');
    }

    /**
     * Hostile missions must not act on a target whose owner is in vacation mode (it may have been
     * activated after the fleet was sent): the fleet turns around instead. Returns true when it did.
     */
    protected function returnIfTargetInVacation($targetOwnerId): bool
    {
        if (empty($targetOwnerId)) {
            return false;
        }

        $sql = 'SELECT `urlaubs_modus` FROM %%USERS%% WHERE `id` = :userId;';
        $vacation = $this->db->selectSingle($sql, [
            ':userId' => $targetOwnerId,
        ], 'urlaubs_modus');

        if (empty($vacation)) {
            return false;
        }

        if ($this->_fleet['group'] != 0 && $this->_fleet['mission'] == MISSION_ATTACK) {
            $sql = 'DELETE %%AKS%%, %%USERS_TO_ACS%%
                FROM %%AKS%%
                LEFT JOIN %%USERS_TO_ACS%% ON `acsID` = %%AKS%%.id
                WHERE %%AKS%%.`id` = :acsId;';
            $this->db->delete($sql, [
                ':acsId' => $this->_fleet['group'],
            ]);
        }

        $this->setState(FLEET_RETURN);
        $this->SaveFleet();

        return true;
    }

    protected function KillFleet()
    {
        $this->kill = 1;
        $sql = 'DELETE %%FLEETS%%, %%FLEETS_EVENT%%
            FROM %%FLEETS%% LEFT JOIN %%FLEETS_EVENT%% on `id` = `fleetId`
            WHERE `id` = :fleetId';

        $this->db->delete($sql, [
            ':fleetId'  => $this->_fleet['id']
        ]);
    }

    protected function getLanguage($language = null, $userID = null)
    {
        if (is_null($language) && !is_null($userID)) {
            $sql = 'SELECT `lang` FROM %%USERS_SETTINGS%% WHERE `user_id` = :userId;';
            $language = $this->db->selectSingle($sql, [
                ':userId' => $userID
            ], 'lang');
        }

        if ($language === null) {
            // language of the current request (cookie / browser): not cacheable
            $LNG = new Language($this->configs, $language);
            $LNG->includeData(['L18N', 'FLEET', 'TECH', 'CUSTOM']);
            return $LNG;
        }

        // Reading and unserializing the language packs is the expensive part: load them once per language
        // and process, hand out copies (callers may add data to their object).
        if (!isset(self::$languageCache[$language])) {
            $LNG = new Language($this->configs, $language);
            $LNG->includeData(['L18N', 'FLEET', 'TECH', 'CUSTOM']);
            self::$languageCache[$language] = $LNG;
        }

        return clone self::$languageCache[$language];
    }

    /** @var array<string, Language> language packs of getLanguage() by language code */
    private static array $languageCache = [];

    public function sendAttackReturnMessage()
    {
        $reslist = & $this->state->reslist;

        $LNG = $this->getLanguage(null, $this->_fleet['owner']);

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetNameStart = $this->db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['start_id'],
        ], 'name');

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetNameEnd	= $this->db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['end_id'],
        ], 'name');

        $Message = sprintf(
            $LNG['sys_fleet_won'],
            $planetNameEnd,
            GetTargetAddressLink($this->_fleet, ''),
            $planetNameStart,
            GetStartAddressLink($this->_fleet, ''),
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_metal']),
            $LNG['tech'][RESOURCE_METAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_crystal']),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $this->viewHelpers()->prettyNumber($this->_fleet['resource_deuterium']),
            $LNG['tech'][RESOURCE_DEUT]
        );

        $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);
        $fleetArrayStart = $this->fleetFunctions()->unserialize($this->_fleet['start_ships']);

        if (isset($fleetArrayStart)) {
            $fleetAmmount = array_sum($fleetArray);
            $fleetAmmountStart = array_sum($fleetArrayStart);
            if ($fleetAmmountStart != $fleetAmmount) {
                if ($fleetAmmountStart > $fleetAmmount) {
                    $Message .= '<br><br>' . sprintf(
                        $LNG['sys_expe_back_home_ships_lost']
                    );
                }
                foreach ($reslist['fleet'] as $shipId) {
                    if (isset($fleetArrayStart[$shipId]) && isset($fleetArray[$shipId])) {
                        if ($fleetArrayStart[$shipId] != $fleetArray[$shipId]) {
                            $Message .= '<br>' . $LNG['tech'][$shipId] . ': ' . $this->viewHelpers()->prettyNumber(abs($fleetArrayStart[$shipId] - $fleetArray[$shipId]));
                        }
                    } elseif (isset($fleetArrayStart[$shipId])) {
                        $Message .= '<br>' . $LNG['tech'][$shipId] . ': ' . $this->viewHelpers()->prettyNumber(abs($fleetArrayStart[$shipId]));
                    } elseif (isset($fleetArray[$shipId])) {
                        $Message .= '<br>' . $LNG['tech'][$shipId] . ': ' . $this->viewHelpers()->prettyNumber(abs($fleetArray[$shipId]));
                    }
                }
            }
        }

        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MESSAGE_TYPE_SYSTEM,
            $LNG['sys_mess_fleetback'],
            $Message,
            $this->_fleet['return_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id']
        );
    }

    protected function sendPiratebaseGoneMessage()
    {
        $LNG = $this->getLanguage(null, $this->_fleet['owner']);

        $message = sprintf(
            $LNG['sys_mess_piratebase_gone'],
            GetTargetAddressLink($this->_fleet, '')
        );

        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $LNG['sys_mess_tower'],
            MESSAGE_TYPE_SYSTEM,
            $LNG['sys_mess_fleetback'],
            $message,
            $this->state->runtime->timestamp,
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id']
        );
    }

    /**
     * Return if the Battlereport has more destroyed Units than the given limit.
     *
     * @param int $units
     * @param int $universe
     *
     * @return boolean
     */
    public function checkForTopKB($units, $universe)
    {
        $db = $this->db;
        $sql = "SELECT `units` FROM %%TOPKB%% 
            WHERE `universe` = :universe AND `memorial` = 0 
            ORDER BY `units` DESC LIMIT 1 OFFSET :offset;";
        $topKbUnits = $db->selectSingle($sql, [
            ':universe'     => $universe,
            ':offset'  => TOPKB_LIMIT - 1,
        ], 'units');

        $result = false;
        if ($units > $topKbUnits) {
            $result = true;
        }

        return $result;
    }
}

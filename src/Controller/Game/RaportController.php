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

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;

/**
 * Battle report page (/game?page=raport): renders a stored combat report for its participants.
 */
#[\App\Routing\GamePage('game.php', 'raport')]
#[\App\Routing\GamePage('game.php', 'raport', uniPrefix: true)]
class RaportController extends AbstractGameController
{
    public static $requireModule = 0;

    protected $disableEcoSystem = true;

    public function initialize(): void
    {
        parent::initialize();
    }

    private function BCWrapperPreRev2321($combatReport, $isDefender)
    {
        if (isset($combatReport['moon']['desfail'])) {
            $combatReport['moon']	= [
                'moonName'				=> $combatReport['moon']['name'],
                'moonChance'			=> $combatReport['moon']['chance'],
                'moonDestroySuccess'	=> !$combatReport['moon']['desfail'],
                'fleetDestroyChance'	=> $combatReport['moon']['chance2'],
                'fleetDestroySuccess'	=> !$combatReport['moon']['fleetfail'],
            ];
        } elseif (isset($combatReport['moon'][0])) {
            $combatReport['moon']	= [
                'moonName'				=> $combatReport['moon'][1],
                'moonChance'			=> $combatReport['moon'][0],
                'moonDestroySuccess'	=> !$combatReport['moon'][2],
                'fleetDestroyChance'	=> $combatReport['moon'][3],
                'fleetDestroySuccess'	=> !$combatReport['moon'][4],
            ];
        }

        if (isset($combatReport['simu'])) {
            $combatReport['additionalInfo'] = $combatReport['simu'];
        }

        if (isset($combatReport['debris'][0])) {
            $combatReport['debris'] = [
                901	=> $combatReport['debris'][0],
                902	=> $combatReport['debris'][1],
            ];
        }

        if (!empty($combatReport['steal']['metal'])) {
            $combatReport['steal'] = [
                901	=> $combatReport['steal']['metal'],
                902	=> $combatReport['steal']['crystal'],
                903	=> $combatReport['steal']['deuterium'],
            ];
        }

        $combatReport['wreckfield_created'] = !empty($combatReport['wreckfield']);
        if (!$isDefender) {
            $combatReport['repaired'] = [];
            $combatReport['wreckfield'] = [];
        }

        return $combatReport;
    }

    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $LNG->includeData(['FLEET']);
        $this->setWindow('popup');
        $pricelist = & $this->state->pricelist;

        $db = $this->db;

        $RID		= $this->http()->_GP('raport', '');

        if ($RID == 0) {
            $this->printMessage($LNG['sys_raport_amount_error']);
        }

        $sql = "SELECT raport,attacker,defender,battleengine FROM %%RW%% WHERE rid = :reportID;";
        $reportData = $db->selectSingle($sql, [
            ':reportID'	=> $RID,
        ]);

        if (empty($reportData)) {
            $this->printMessage($LNG['sys_raport_not_found']);
        }

        // empty is BC for pre r2484
        $attackers = explode(",", $reportData['attacker']);
        $defenders = explode(",", $reportData['defender']);
        $isAttacker = empty($reportData['attacker']) || in_array($USER['id'], $attackers);
        $isDefender = empty($reportData['defender']) || in_array($USER['id'], $defenders);
        if (array_key_exists('authlevel', $USER) && $USER['authlevel'] > 0) {
            $isDefender = true;
        }
        $showDetails = $isAttacker || $isDefender;
        $sql = "SELECT ally_id FROM %%USERS%% WHERE id = :pID;";
        $allyID = $db->selectSingle($sql, [
            ':pID'	=> $USER['id'],
        ], 'ally_id');
        if (!$showDetails && $allyID > 0) {
            foreach ($attackers as $atterID) {
                $aID = $db->selectSingle($sql, [
                    ':pID'	=> $atterID,
                ], 'ally_id');
                if ($allyID == $aID) {
                    $showDetails = true;
                    break;
                }
            }
            if (!$showDetails) {
                foreach ($defenders as $defferID) {
                    $aID = $db->selectSingle($sql, [
                        ':pID'	=> $defferID,
                    ], 'ally_id');
                    if ($allyID == $aID) {
                        $showDetails = true;
                        break;
                    }
                }
            }
        }

        $Info = null;
        if (!$showDetails) {
            $sql = "SELECT
			battleengine, raport, time,
			(
				SELECT
				GROUP_CONCAT(username SEPARATOR ' & ') as attacker
				FROM %%USERS%%
				WHERE id IN (SELECT uid FROM %%TOPKB_USERS%% WHERE %%TOPKB_USERS%%.rid = %%RW%%.rid AND role = 1)
			) as attacker,
			(
				SELECT
				GROUP_CONCAT(username SEPARATOR ' & ') as defender
				FROM %%USERS%%
				WHERE id IN (SELECT uid FROM %%TOPKB_USERS%% WHERE %%TOPKB_USERS%%.rid = %%RW%%.rid AND role = 2)
			) as defender
			FROM %%RW%%
			WHERE rid = :reportID;";
            $reportData = $db->selectSingle($sql, [
                ':reportID'	=> $RID,
            ]);

            $Info		= [$reportData["attacker"], $reportData["defender"]];
        }

        if (empty($reportData)) {
            $this->printMessage($LNG['sys_raport_not_found']);
        }

        if ($reportData['battleengine'] === 'rust') {
            $combatReport = json_decode(decompressFromBase64($reportData['raport']), true);
        } else {
            $combatReport = unserialize($reportData['raport']);
        }

        if (!$isDefender && $combatReport['result'] == DEFENDER && count($combatReport['rounds']) <= 2) {
            $this->printMessage($LNG['sys_raport_lost_contact']);
        }

        $combatReport['time']	= $this->viewHelpers()->formatDate($LNG['php_tdformat'], $combatReport['time'], $USER['timezone']);
        $combatReport			= $this->BCWrapperPreRev2321($combatReport, $isDefender);

        if ($reportData['battleengine'] === 'rust') {
            foreach ($combatReport['rounds'] as $rkey => $round) {
                ksort($round['attacker']['Roundstats']);
                ksort($round['defender']['Roundstats']);
                if (isset($round['defender']['Roundstats'][0])) {
                    $planet = $round['defender']['Roundstats'][0];
                    unset($round['defender']['Roundstats'][0]);
                    $round['defender']['Roundstats'][0] = $planet;
                }
                foreach ($round['attacker']['Roundstats'] as $key => $value) {
                    ksort($value);
                    $round['attacker']['Roundstats'][$key] = $value;
                }
                foreach ($round['defender']['Roundstats'] as $key => $value) {
                    ksort($value);
                    $round['defender']['Roundstats'][$key] = $value;
                }
                $combatReport['rounds'][$rkey] = $round;
            }
        }
        $this->assign([
            'Raport'        => $combatReport,
            'Info'          => $Info,
            'pageTitle'     => $LNG['sys_mess_attack_report'],
            'recCapacity'   => $pricelist[SHIP_RECYCLER]['capacity'],
        ]);

        if ($reportData['battleengine'] === 'rust') {
            return $this->render('game/shared.mission.raport.rust.html.twig');
        } else {
            return $this->render('game/shared.mission.raport.html.twig');
        }
    }
}

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
use App\Game\Core\StoredData;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;

/**
 * Battle report page (/game?page=raport): renders a stored combat report for its participants.
 */
#[\App\Routing\GamePage('game', 'raport')]
class RaportController extends AbstractGameController
{
    protected $disableEcoSystem = true;

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Marks whether a wreck field was created and hides repair data from viewers that are not the defender.
     */
    private function applyViewerPerspective($combatReport, $isDefender)
    {
        $combatReport['wreckfield_created'] = !empty($combatReport['wreckfield']);
        if (!$isDefender) {
            $combatReport['repaired'] = [];
            $combatReport['wreckfield'] = [];
        }

        return $combatReport;
    }

    /**
     * Combat report (request: raport = report id from RW). Participants (attacker or defender id lists),
     * admins and members of the alliances of participants see the full report; everybody else only a reduced one with the participants' names
     * (from TOPKB_USERS). Renders the template for the battle engine stored with the report (`rust` or classic). Unknown ids end in a message.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $LNG->includeData(['FLEET']);
        $this->setWindow('popup');
        $e = $this->elements();

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

        [$showDetails, $isDefender] = $this->detailAccess($reportData);

        $Info = null;
        if (!$showDetails) {
            $reportData = $this->reducedReport($RID);
            $Info		= [$reportData["attacker"], $reportData["defender"]];
        }

        if (empty($reportData)) {
            $this->printMessage($LNG['sys_raport_not_found']);
        }

        if ($reportData['battleengine'] === 'rust') {
            $combatReport = json_decode(decompressFromBase64($reportData['raport']), true);
        } else {
            $combatReport = StoredData::decode($reportData['raport']);
        }

        if (!$isDefender && $combatReport['result'] == DEFENDER && count($combatReport['rounds']) <= 2) {
            $this->printMessage($LNG['sys_raport_lost_contact']);
        }

        $combatReport['time']	= $this->viewHelpers()->formatDate($LNG['php_tdformat'], $combatReport['time'], $USER['timezone']);
        $combatReport			= $this->applyViewerPerspective($combatReport, $isDefender);

        if ($reportData['battleengine'] === 'rust') {
            $combatReport = $this->sortRustRounds($combatReport);
        }
        $this->assign([
            'Raport'        => $combatReport,
            'Info'          => $Info,
            'pageTitle'     => $LNG['sys_mess_attack_report'],
            'recCapacity'   => $e->capacity(SHIP_RECYCLER),
        ]);

        if ($reportData['battleengine'] === 'rust') {
            return $this->render('game/shared.mission.raport.rust.html.twig');
        } else {
            return $this->render('game/shared.mission.raport.html.twig');
        }
    }

    /**
     * Decides what the viewer may see: participants (attacker or defender id lists),
     * admins (also treated as defenders) and members of the alliances of any participant get the full report.
     *
     * @param array<string, mixed> $reportData RW row with the attacker and defender id lists
     * @return array{0: bool, 1: bool} show the full report, view as defender
     */
    private function detailAccess(array $reportData): array
    {
        $USER = $this->state->USER;
        $db = $this->db;

        $attackers = explode(",", $reportData['attacker']);
        $defenders = explode(",", $reportData['defender']);
        $isAttacker = in_array((string) $USER['id'], $attackers, true);
        $isDefender = in_array((string) $USER['id'], $defenders, true);
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

        return [$showDetails, $isDefender];
    }

    /**
     * The report row for viewers without access: the report itself plus the names of the attackers and defenders
     * (from TOPKB_USERS) joined with " & ".
     *
     * @return array<string, mixed>|false|null row with battleengine, raport, time, attacker (names) and defender (names)
     */
    private function reducedReport(mixed $RID): array|false|null
    {
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

        return $this->db->selectSingle($sql, [
            ':reportID'	=> $RID,
        ]);
    }

    /**
     * Brings the rounds of a rust engine report into the order the template expects: round stats sorted by id with the
     * defending planet (id 0) last.
     *
     * @param array<string, mixed> $combatReport decoded report
     * @return array<string, mixed>
     */
    private function sortRustRounds(array $combatReport): array
    {
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

        return $combatReport;
    }
}

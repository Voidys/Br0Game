<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use App\Twig\GameEscaping;
use Symfony\Component\HttpFoundation\Response;
use Twig\Markup;
use Exception;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Alliance pages: overview, application, creation, member list, circular messages and the admin area (texts, ranks, members, diplomacy).
 */
#[\App\Routing\GamePage('game.php', 'alliance')]
#[\App\Routing\GamePage('game.php', 'alliance', uniPrefix: true)]
class AllianceController extends AbstractGameController
{
    public static $requireModule = 'MODULE_ALLIANCE';

    private $allianceData;
    private $ranks;
    private $rights;
    private $hasAlliance = false;
    private $hasApply = false;
    public $availableRanks = [
        'MEMBERLIST',
        'ONLINESTATE',
        'TRANSFER',
        'SEEAPPLY',
        'MANAGEAPPLY',
        'ROUNDMAIL',
        'ADMIN',
        'KICK',
        'DIPLOMATIC',
        'RANKS',
        'MANAGEUSERS',
    ];

    public function initialize(): void
    {
        $USER = & $this->state->USER;
        parent::initialize();
        $this->hasAlliance = $USER['ally_id'] != 0;
        $this->hasApply = $this->isApply();
        if ($this->hasAlliance && !$this->hasApply) {
            $this->setAllianceData($USER['ally_id']);
        }
    }

    /**
     * A translation with HTML (<b>, <br>) filled with values: the arguments are escaped (existing
     * entities stay), the result is marked as safe HTML for the message/page templates.
     */
    private function htmlText(string $format, string|int|Markup ...$arguments): Markup
    {
        $escaped = array_map(
            static fn(string|int|Markup $argument): string => $argument instanceof Markup ? (string) $argument : GameEscaping::escape($argument),
            $arguments,
        );

        return new Markup(sprintf($format, ...$escaped), 'UTF-8');
    }

    /** a stored multi-line text as safe HTML: escaped (existing entities stay), line breaks as <br> */
    private function multilineHtml(?string $text): Markup
    {
        return new Markup(nl2br(GameEscaping::escape($text ?? '')), 'UTF-8');
    }

    private function setAllianceData($allianceId)
    {
        $USER = & $this->state->USER;
        $db = $this->db;

        $sql = 'SELECT * FROM %%ALLIANCE%% WHERE id = :allianceId;';
        $this->allianceData = $db->selectSingle($sql, [
            ':allianceId' => $allianceId,
        ]);

        if (!$this->allianceData) {
            $this->printMessage($this->state->LNG['al_not_exists']);
        }

        if ($USER['ally_id'] == $allianceId) {
            if ($this->allianceData['ally_owner'] == $USER['id']) {
                $this->rights = array_combine($this->availableRanks, array_fill(0, count($this->availableRanks), true));
            } elseif ($USER['ally_rank_id'] != 0) {
                $sql = 'SELECT ' . implode(
                    ', ',
                    $this->availableRanks,
                ) . ' FROM %%ALLIANCE_RANK%% WHERE allianceId = :allianceId AND rankID = :ally_rank_id;';
                $this->rights = $db->selectSingle($sql, [
                    ':allianceId' => $allianceId,
                    ':ally_rank_id' => $USER['ally_rank_id'],
                ]);
            }

            if (!isset($this->rights)) {
                $this->rights = array_combine(
                    $this->availableRanks,
                    array_fill(0, count($this->availableRanks), false),
                );
            }

            $this->assign([
                'rights' => $this->rights,
                'AllianceOwner' => $this->allianceData['ally_owner'] == $USER['id'],
            ]);
        }
    }

    private function isApply()
    {
        $USER = & $this->state->USER;
        $db = $this->db;
        $sql = "SELECT `allianceID` FROM %%ALLIANCE_REQUEST%% WHERE userId = :userId;";
        $requestAllianceId = $db->selectSingle($sql, [
            ':userId' => $USER['id'],
        ], 'allianceID');

        $sql = "SELECT `id` FROM %%ALLIANCE%% WHERE `id` = :requestAllianceId;";
        $allianceId = $db->selectSingle($sql, [
            ':requestAllianceId' => $requestAllianceId,
        ], 'id');

        // Alliance does no longer exists?
        if (empty($allianceId)) {
            return false;
        }

        return true;
    }

    public function info(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $allianceId = $this->http()->_GP('id', 0);

        $statisticData = [
            'totalfight' => 0,
            'fightwon' => 0,
            'fightlose' => 0,
            'fightdraw' => 0,
            'unitsshot' => 0,
            'unitslose' => 0,
            'dermetal' => 0,
            'dercrystal' => 0,
        ];
        $diplomaticmaticData = false;
        $diplomats = false;

        $this->setAllianceData($allianceId);

        if (!isset($this->allianceData)) {
            $this->printMessage($LNG['al_not_exists']);
        }

        if ($this->allianceData['ally_diplo'] == 1) {
            $diplomaticmaticData = $this->getDiplomatic();

            $sql = "SELECT u.id, u.username, r.rankName FROM %%USERS%% u
                JOIN %%ALLIANCE_RANK%% r ON r.allianceID = u.ally_id AND r.rankID = u.ally_rank_id
                WHERE r.DIPLOMATIC = 1 AND u.ally_id = :ally_id;";
            $diplomats = $db->select($sql, [':ally_id' => $this->allianceData['id']]);
        }
        $show = true;
        if ($this->allianceData['ally_stats'] == 1) {
            if (!$this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS) || $USER['spy_tech'] >= 6 || $USER['authlevel'] > 0) {
                $sql = "SELECT id FROM %%USERS%% WHERE ally_id = :allyID;";
                $users = $db->select($sql, [
                    ':allyID' => $this->allianceData['id'],
                ]);

                $statisticResult = $statsHandler->getSummedStats(array_column($users, 'id'), [
                    AdvancedStatsEnum::WONS,
                    AdvancedStatsEnum::LOOS,
                    AdvancedStatsEnum::DRAWS,
                    AdvancedStatsEnum::KBMETAL,
                    AdvancedStatsEnum::KBCRYSTAL,
                    AdvancedStatsEnum::LOSTUNITS,
                    AdvancedStatsEnum::DESUNITS,
                ]);

                $statisticData = [
                    'totalfight' => $statisticResult['wons'] + $statisticResult['loos'] + $statisticResult['draws'],
                    'fightwon' => $statisticResult['wons'],
                    'fightlose' => $statisticResult['loos'],
                    'fightdraw' => $statisticResult['draws'],
                    'unitsshot' => $this->viewHelpers()->prettyNumber($statisticResult['desunits']),
                    'unitslose' => $this->viewHelpers()->prettyNumber($statisticResult['lostunits']),
                    'dermetal' => $this->viewHelpers()->prettyNumber($statisticResult['kbmetal']),
                    'dercrystal' => $this->viewHelpers()->prettyNumber($statisticResult['kbcrystal']),
                ];
            } else {
                $show = false;
            }
        }

        $sql = 'SELECT total_points
		FROM %%STATPOINTS%%
		WHERE id_owner = :userId AND stat_type = :statType';

        $userPoints = $this->db->selectSingle($sql, [
            ':userId' => $USER['id'],
            ':statType' => 1,
        ], 'total_points');
        if (isset($this->allianceData['ally_description'])) {
            $allydesc = $this->multilineHtml($this->allianceData['ally_description']);
        } else {
            $allydesc = '';
        }

        $chartData = $this->playerUtil()->getAllianceChartPoints($this->allianceData['id'], $USER);

        $this->assign([
            'diplomaticData' => $diplomaticmaticData,
            'diplomats' => $diplomats,
            'statisticData' => $statisticData,
            'ally_description' => $allydesc,
            'ally_id' => $this->allianceData['id'],
            'ally_image' => $this->allianceData['ally_image'],
            'ally_web' => $this->allianceData['ally_web'],
            'ally_member_scount' => $this->allianceData['ally_members'],
            'ally_max_members' => $this->allianceData['ally_max_members'],
            'ally_name' => $this->allianceData['ally_name'],
            'ally_tag' => $this->allianceData['ally_tag'],
            'ally_stats' => $this->allianceData['ally_stats'],
            'ally_diplo' => $this->allianceData['ally_diplo'],
            'ally_request' => !$this->hasAlliance
                && !$this->hasApply
                && $this->allianceData['ally_request_notallow'] == 0
                && $this->allianceData['ally_max_members'] > $this->allianceData['ally_members'],
            'ally_request_min_points' => $userPoints >= $this->allianceData['ally_request_min_points'],
            'ally_request_min_points_info' => sprintf(
                $LNG['al_requests_min_points'],
                $this->viewHelpers()->prettyNumber($this->allianceData['ally_request_min_points']),
            ),
            'show' => $show,
            'totalfight' => $statisticData['totalfight'],
            'fightwon' => $statisticData['fightwon'],
            'fightlose' => $statisticData['fightlose'],
            'fightdraw' => $statisticData['fightdraw'],
            'unitsshot' => $statisticData['unitsshot'],
            'unitslose' => $statisticData['unitslose'],
            'dermetal' => $statisticData['dermetal'],
            'dercrystal' => $statisticData['dercrystal'],
            'chartData' => $chartData,
        ]);

        return $this->render('game/page.alliance.info.html.twig');
    }

    public function show(): Response
    {
        if ($this->hasAlliance) {
            return $this->homeAlliance();
        } elseif ($this->hasApply) {
            return $this->applyWaitScreen();
        } else {
            return $this->createSelection();
        }
    }

    private function redirectToHome()
    {
        $this->redirectTo('game?page=alliance');
    }

    private function getAction()
    {
        return $this->http()->_GP('action', '');
    }

    private function applyWaitScreen(): Response
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $db = $this->db;
        $sql = "SELECT a.id, a.ally_name, a.ally_tag
            FROM %%ALLIANCE_REQUEST%% r
            INNER JOIN %%ALLIANCE%% a ON a.id = r.allianceId
            WHERE r.userId = :userId;";
        $allianceResult = $db->selectSingle($sql, [
            ':userId' => $USER['id'],
        ]);

        if (empty($allianceResult['ally_tag'])) {
            $allianceResult['ally_tag'] = 0;
        }

        $link = new Markup(
            '<a href="?page=alliance&amp;mode=info&amp;id=' . (int) $allianceResult['id'] . '">['
            . GameEscaping::escape($allianceResult['ally_tag']) . '] ' . GameEscaping::escape($allianceResult['ally_name']) . '</a>',
            'UTF-8',
        );
        $this->assign([
            'request_text' => $this->htmlText($LNG['al_request_wait_message'], $link),
        ]);

        return $this->render('game/page.alliance.applyWait.html.twig');
    }

    private function createSelection(): Response
    {
        return $this->render('game/page.alliance.createSelection.html.twig');
    }

    public function search(): Response
    {
        if ($this->hasApply) {
            $this->redirectToHome();
        }

        $searchText = $this->http()->_GP('searchtext', '', UTF8_SUPPORT);
        $searchList = [];

        if (!empty($searchText)) {
            $db = $this->db;
            $sql = "SELECT id, ally_name, ally_tag, ally_members
			FROM %%ALLIANCE%%
			WHERE ally_universe = :universe AND ally_name LIKE :searchTextLike
			ORDER BY (
			  IF(ally_name = :searchTextEqual, 1, 0) + IF(ally_name LIKE :searchTextLike, 1, 0)
			) DESC,ally_name ASC LIMIT 25;";

            $searchResult = $db->select($sql, [
                ':universe' => $this->universe()->current(),
                ':searchTextLike' => '%' . $searchText . '%',
                ':searchTextEqual' => $searchText,
            ]);

            foreach ($searchResult as $searchRow) {
                $searchList[] = [
                    'id' => $searchRow['id'],
                    'tag' => $searchRow['ally_tag'],
                    'members' => $searchRow['ally_members'],
                    'name' => $searchRow['ally_name'],
                ];
            }
        }

        $this->assign([
            'searchText' => $searchText,
            'searchList' => $searchList,
        ]);

        return $this->render('game/page.alliance.search.html.twig');
    }

    public function apply(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        if ($this->hasApply) {
            $this->redirectToHome();
        }

        if ($USER['ally_id'] != 0) {
            $this->redirectToHome();
        }

        $text = $this->http()->_GP('text', '', true);
        $allianceId = $this->http()->_GP('id', 0);

        $db = $this->db;
        $sql = "SELECT ally_tag, ally_request, ally_request_notallow, ally_owner, ally_members, ally_max_members, ally_request_min_points 
            FROM %%ALLIANCE%% WHERE id = :allianceId AND ally_universe = :universe;";
        $allianceResult = $db->selectSingle($sql, [
            ':allianceId' => $allianceId,
            ':universe' => $this->universe()->current(),
        ]);

        if (!isset($allianceResult)) {
            $this->redirectToHome();
        }

        if ($allianceResult['ally_request_notallow'] == 1) {
            $this->printMessage($LNG['al_alliance_closed'], [[
                'label' => $LNG['sys_forward'],
                'url' => '?page=alliance',
            ]]);
        }

        if ($allianceResult['ally_members'] >= $allianceResult['ally_max_members']) {
            $this->printMessage($LNG['al_alliance_full'], [[
                'label' => $LNG['sys_forward'],
                'url' => '?page=alliance',
            ]]);
        }

        if ($allianceResult['ally_request_min_points'] > 0) {
            $sql = 'SELECT total_points FROM %%STATPOINTS%% WHERE id_owner = :userId AND stat_type = :statType';
            $userPoints = $this->db->selectSingle($sql, [
                ':userId' => $USER['id'],
                ':statType' => 1,
            ], 'total_points');
            if ($userPoints < $allianceResult['ally_request_min_points']) {
                $this->printMessage(sprintf($LNG['al_alliance_min_points'], $allianceResult['ally_request_min_points']), [[
                    'label' => $LNG['sys_forward'],
                    'url' => '?page=alliance',
                ]]);
            }
        }

        if (!empty($text)) {
            $sql = "INSERT INTO %%ALLIANCE_REQUEST%% SET
                allianceId	= :allianceId,
                text		= :text,
                time		= :time,
                userId		= :userId;";

            $db->insert($sql, [
                ':allianceId' => $allianceId,
                ':text' => $text,
                ':time' => $this->state->runtime->timestamp,
                ':userId' => $USER['id'],
            ]);

            $receivers = $this->getMessageReceivers($allianceId, false, true);

            foreach ($receivers as $receiver) {
                $lang = $this->playerUtil()->getLanguage($receiver['lang']);

                $applyMessage = sprintf(
                    $lang['al_new_apply'],
                    $USER['id'],
                    $USER['username'],
                    $USER['username'],
                );

                $this->playerUtil()->sendMessage(
                    $receiver['id'],
                    0,
                    $lang['al_alliance'],
                    MESSAGE_TYPE_ALLIANCE,
                    $lang['al_request'],
                    $applyMessage,
                    $this->state->runtime->timestamp,
                );
            }

            $this->printMessage($this->htmlText($LNG['al_request_confirmation_message']), [[
                'label' => $LNG['al_ok'],
                'url' => '?page=overview',
            ]]);
        }

        $this->assign([
            'allyid' => $allianceId,
            'applytext' => $allianceResult['ally_request'],
            'al_write_request' => $this->htmlText($LNG['al_write_request'], $allianceResult['ally_tag']),
        ]);

        return $this->render('game/page.alliance.apply.html.twig');
    }

    public function cancelApply()
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        if (!$this->hasApply) {
            $this->redirectToHome();
        }

        $db = $this->db;
        $sql = "SELECT a.ally_tag FROM %%ALLIANCE_REQUEST%% r INNER JOIN %%ALLIANCE%% a ON a.id = r.allianceId"
            . " WHERE r.userId = :userId;";
        $allyTag = $db->selectSingle($sql, [
            ':userId' => $USER['id'],
        ], 'ally_tag');

        $sql = "DELETE FROM %%ALLIANCE_REQUEST%% WHERE userId = :userId;";
        $db->delete($sql, [
            ':userId' => $USER['id'],
        ]);

        $this->printMessage($this->htmlText($LNG['al_request_deleted'], $allyTag), [[
            'label' => $LNG['sys_forward'],
            'url' => '?page=alliance',
        ]]);
    }

    public function create(): Response
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        if ($this->hasApply) {
            $this->redirectToHome();
        }
        $sql = 'SELECT total_points
		FROM %%STATPOINTS%%
		WHERE id_owner = :userId AND stat_type = :statType';

        $userPoints = $this->db->selectSingle($sql, [
            ':userId' => $USER['id'],
            ':statType' => 1,
        ], 'total_points');

        $min_points = $this->configs->get()->alliance_create_min_points;

        if ($userPoints >= $min_points) {
            $action = $this->getAction();
            if ($action == "send") {
                return $this->createAlliance();
            } else {
                return $this->render('game/page.alliance.create.html.twig');
            }
        } else {
            $diff_points = $min_points - $userPoints;
            $messageText = $this->htmlText(
                $LNG['al_make_ally_insufficient_points'],
                $this->viewHelpers()->prettyNumber($min_points),
                $this->viewHelpers()->prettyNumber($diff_points),
            );

            $this->printMessage($messageText, [[
                'label' => $LNG['sys_back'],
                'url' => '?page=alliance',
            ]]);
        }
    }

    private function createAlliance(): Response
    {
        $action = $this->getAction();
        if ($action == "send") {
            $this->createAllianceProcessor();
        } else {
            return $this->render('game/page.alliance.create.html.twig');
        }
    }

    private function createAllianceProcessor()
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $allianceTag = $this->http()->_GP('atag', '', UTF8_SUPPORT);
        $allianceName = $this->http()->_GP('aname', '', UTF8_SUPPORT);

        $allianceTag = preg_replace('/[^a-zA-Z0-9 (-)_äöüßÄÖÜ]/', '', $allianceTag);
        $allianceName = preg_replace('/[^a-zA-Z0-9 (-)_äöüßÄÖÜ]/', '', $allianceName);

        if (empty($allianceTag)) {
            $this->printMessage($LNG['al_tag_required'], [[
                'label' => $LNG['sys_back'],
                'url' => '?page=alliance&mode=create',
            ]]);
        }

        if (empty($allianceName)) {
            $this->printMessage($LNG['al_name_required'], [[
                'label' => $LNG['sys_back'],
                'url' => '?page=alliance&mode=create',
            ]]);
        }

        if (mb_strlen($allianceTag) > 8 || mb_strlen($allianceName) > 30) {
            $this->printMessage($LNG['al_name_tag_too_long'], [[
                'label' => $LNG['sys_back'],
                'url' => '?page=alliance&mode=create',
            ]]);
        }

        if (!$this->playerUtil()->isNameValid($allianceName) || !$this->playerUtil()->isNameValid($allianceTag)) {
            $this->printMessage($LNG['al_newname_specialchar'], [[
                'label' => $LNG['sys_back'],
                'url' => '?page=alliance&mode=create',
            ]]);
        }

        $db = $this->db;

        $sql = 'SELECT COUNT(*) as count FROM %%ALLIANCE%% WHERE ally_universe = :universe
        AND (ally_tag = :allianceTag OR ally_name = :allianceName);';

        $allianceCount = $db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
            ':allianceTag' => $allianceTag,
            ':allianceName' => $allianceName,
        ], 'count');

        if ($allianceCount != 0) {
            $this->printMessage($this->htmlText($LNG['al_already_exists'], $allianceName), [[
                'label' => $LNG['sys_back'],
                'url' => '?page=alliance&mode=create',
            ]]);
        }

        $sql = "INSERT INTO %%ALLIANCE%% SET ally_name = :allianceName, ally_tag = :allianceTag, ally_owner = :userId,"
            . " ally_owner_range = :allianceOwnerRange, ally_members = 1, ally_register_time = :time,"
            . " ally_universe = :universe;";
        $db->insert($sql, [
            ':allianceName' => $allianceName,
            ':allianceTag' => $allianceTag,
            ':userId' => $USER['id'],
            ':allianceOwnerRange' => $LNG['al_default_leader_name'],
            ':time' => $this->state->runtime->timestamp,
            ':universe' => $this->universe()->current(),
        ]);

        // $sql = "SELECT `id` FROM %%ALLIANCE%% WHERE ally_name = :allianceName AND ally_tag = :allianceTag AND ally_owner = :userId AND ally_universe = :universe";
        // $allianceId = $db->selectSingle($sql, [
        //     ':allianceName' => $allianceName,
        //     ':allianceTag' => $allianceTag,
        //     ':userId' => $USER['id'],
        //     ':universe' => Universe::current(),
        // ], 'id');

        $allianceId = $db->lastInsertId();

        $sql = "UPDATE %%USERS%% SET ally_id	= :allianceId, ally_rank_id	= 0, ally_register_time = :time"
            . " WHERE id = :userId;";
        $db->update($sql, [
            ':allianceId' => $allianceId,
            ':time' => $this->state->runtime->timestamp,
            ':userId' => $USER['id'],
        ]);

        $sql = "UPDATE %%STATPOINTS%% SET id_ally = :allianceId WHERE id_owner = :userId;";
        $db->update($sql, [
            ':allianceId' => $allianceId,
            ':userId' => $USER['id'],
        ]);

        $this->printMessage($this->htmlText($LNG['al_created'], $allianceName . ' [' . $allianceTag . ']'), [[
            'label' => $LNG['sys_forward'],
            'url' => '?page=alliance',
        ]]);
    }

    private function getDiplomatic()
    {
        $Return = [];
        $db = $this->db;

        $sql = "SELECT d.level, d.accept, d.accept_text, d.id, a.id as ally_id, a.ally_name, a.ally_tag, d.owner_1,"
            . " d.owner_2 FROM %%DIPLO%% as d INNER JOIN %%ALLIANCE%% as a ON IF(:allianceId = d.owner_1,"
            . " a.id = d.owner_2, a.id = d.owner_1) WHERE :allianceId = d.owner_1 OR :allianceId = d.owner_2;";
        $DiploResult = $db->select($sql, [
            ':allianceId' => $this->allianceData['id'],
        ]);

        foreach ($DiploResult as $CurDiplo) {
            if ($CurDiplo['level'] == 1 && $CurDiplo['owner_2'] == $this->allianceData['id']) {
                $CurDiplo['level'] = 0;
            }

            if ($CurDiplo['accept'] == 0 && $CurDiplo['owner_2'] == $this->allianceData['id']) {
                $Return[5][$CurDiplo['id']] = [
                    $CurDiplo['ally_name'],
                    $CurDiplo['ally_id'],
                    $CurDiplo['level'],
                    $CurDiplo['accept_text'],
                    $CurDiplo['ally_tag'],
                ];
            } elseif ($CurDiplo['accept'] == 0 && $CurDiplo['owner_1'] == $this->allianceData['id']) {
                $Return[6][$CurDiplo['id']] = [
                    $CurDiplo['ally_name'],
                    $CurDiplo['ally_id'],
                    $CurDiplo['level'],
                    $CurDiplo['accept_text'],
                    $CurDiplo['ally_tag'],
                ];
            } else {
                $Return[$CurDiplo['level']][$CurDiplo['id']] = [
                    $CurDiplo['ally_name'],
                    $CurDiplo['ally_id'],
                    $CurDiplo['owner_1'],
                    $CurDiplo['ally_tag'],
                ];
            }
        }
        return $Return;
    }

    private function homeAlliance(): Response
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        if ($this->allianceData['ally_owner'] == $USER['id']) {
            $rankName = ($this->allianceData['ally_owner_range'] != '')
                ? $this->allianceData['ally_owner_range'] : $LNG['al_founder_rank_text'];
        } elseif ($USER['ally_rank_id'] != 0) {
            $sql = "SELECT rankName FROM %%ALLIANCE_RANK%% WHERE rankID = :UserRankID;";
            $rankName = $db->selectSingle($sql, [
                ':UserRankID' => $USER['ally_rank_id'],
            ], 'rankName');
        }

        if (empty($rankName)) {
            $rankName = $LNG['al_new_member_rank_text'];
        }

        $sql = "SELECT id FROM %%USERS%% WHERE ally_id = :allyID;";
        $users = $db->select($sql, [
            ':allyID' => $this->allianceData['id'],
        ]);

        $statisticResult = $statsHandler->getSummedStats(array_column($users, 'id'), [
            AdvancedStatsEnum::WONS,
            AdvancedStatsEnum::LOOS,
            AdvancedStatsEnum::DRAWS,
            AdvancedStatsEnum::KBMETAL,
            AdvancedStatsEnum::KBCRYSTAL,
            AdvancedStatsEnum::LOSTUNITS,
            AdvancedStatsEnum::DESUNITS,
        ]);

        $sql = "SELECT u.id, u.username, r.rankName FROM %%USERS%% u
                JOIN %%ALLIANCE_RANK%% r ON r.allianceID = u.ally_id AND r.rankID = u.ally_rank_id
                WHERE r.DIPLOMATIC = 1 AND u.ally_id = :ally_id;";
        $diplomats = $this->db->select($sql, [':ally_id' => $this->allianceData['id']]);

        $sql = "SELECT COUNT(*) as count FROM %%ALLIANCE_REQUEST%% WHERE allianceId = :AllianceID;";
        $ApplyCount = $db->selectSingle($sql, [
            ':AllianceID' => $this->allianceData['id'],
        ], 'count');

        $sql = "SELECT COUNT(*) as count FROM %%DIPLO%% WHERE owner_2 = :AllianceID AND accept = 0;";
        $DiploCountIn = $db->selectSingle($sql, [
            ':AllianceID' => $this->allianceData['id'],
        ], 'count');
        $sql = "SELECT COUNT(*) as count FROM %%DIPLO%% WHERE owner_1 = :AllianceID AND accept = 0;";
        $DiploCountOut = $db->selectSingle($sql, [
            ':AllianceID' => $this->allianceData['id'],
        ], 'count');
        if (isset($this->allianceData['ally_description'])) {
            $allydesc = $this->multilineHtml($this->allianceData['ally_description']);
        } else {
            $allydesc = '';
        }
        if (isset($this->allianceData['ally_text'])) {
            $allytext = $this->multilineHtml($this->allianceData['ally_text']);
        } else {
            $allytext = '';
        }
        $show = true;
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS) || $USER['spy_tech'] >= 6 || $USER['authlevel'] > 0) {
            $fightdata = [
                'totalfight' => $statisticResult['wons'] + $statisticResult['loos'] + $statisticResult['draws'],
                'wons' => $statisticResult['wons'],
                'loos' => $statisticResult['loos'],
                'draws' => $statisticResult['draws'],
                'kbmetal' => $statisticResult['kbmetal'],
                'kbcrystal' => $statisticResult['kbcrystal'],
                'lostunits' => $statisticResult['lostunits'],
                'desunits' => $statisticResult['desunits'],
            ];
        } else {
            $fightdata = [
                'totalfight' => 0,
                'wons' => 0,
                'loos' => 0,
                'draws' => 0,
                'kbmetal' => 0,
                'kbcrystal' => 0,
                'lostunits' => 0,
                'desunits' => 0,
            ];
            $show = false;
        }

        $chartData = $this->playerUtil()->getAllianceChartPoints($this->allianceData['id'], $USER);

        $this->assign([
            'DiploInfo' => $this->getDiplomatic(),
            'ally_web' => $this->allianceData['ally_web'],
            'ally_tag' => $this->allianceData['ally_tag'],
            'ally_members' => $this->allianceData['ally_members'],
            'ally_max_members' => $this->allianceData['ally_max_members'],
            'ally_name' => $this->allianceData['ally_name'],
            'ally_image' => $this->allianceData['ally_image'],
            'ally_description' => $allydesc,
            'ally_text' => $allytext,
            'rankName' => $rankName,
            'requests' => sprintf($LNG['al_new_requests'], $ApplyCount),
            'applyCount' => $ApplyCount,
            'diplomats' => $diplomats,
            'diploRequestsIn' => $LNG['al_diplo_accept'] . ' - ' . $DiploCountIn,
            'diploRequestsOut' => $LNG['al_diplo_accept_send'] . ' - ' . $DiploCountOut,
            'diploCountIn' => $DiploCountIn,
            'diploCountOut' => $DiploCountOut,
            'totalfight' => $fightdata['totalfight'],
            'fightwon' => $fightdata['wons'],
            'fightlose' => $fightdata['loos'],
            'fightdraw' => $fightdata['draws'],
            'unitsshot' => $this->viewHelpers()->prettyNumber($fightdata['desunits']),
            'unitslose' => $this->viewHelpers()->prettyNumber($fightdata['lostunits']),
            'dermetal' => $this->viewHelpers()->prettyNumber($fightdata['kbmetal']),
            'dercrystal' => $this->viewHelpers()->prettyNumber($fightdata['kbcrystal']),
            'isOwner' => $this->allianceData['ally_owner'] == $USER['id'],
            'show' => $show,
            'chartData' => $chartData,
        ]);

        return $this->render('game/page.alliance.home.html.twig');
    }

    public function memberList(): Response
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        if (!$this->rights['MEMBERLIST']) {
            $this->redirectToHome();
        }

        $rankList = [];

        $db = $this->db;
        $sql = "SELECT rankID, rankName FROM %%ALLIANCE_RANK%% WHERE allianceId = :AllianceID";
        $rankResult = $db->select($sql, [
            ':AllianceID' => $this->allianceData['id'],
        ]);

        foreach ($rankResult as $rankRow) {
            $rankList[$rankRow['rankID']] = $rankRow['rankName'];
        }

        $memberList = [];

        $sql = "SELECT DISTINCT u.id, u.username,u.galaxy, u.system, u.planet, u.banaday, u.urlaubs_modus, 
            u.ally_register_time, u.onlinetime, u.ally_rank_id, s.total_points, u.rebuild_protection, u.rebuild_protection_attackers
            FROM %%USERS%% u 
            LEFT JOIN %%STATPOINTS%% as s ON s.stat_type = '1' AND s.id_owner = u.id 
            WHERE ally_id = :AllianceID;";

        $memberListResult = $db->select($sql, [
            ':AllianceID' => $this->allianceData['id'],
        ]);

        try {
            $USER += $db->selectSingle(
                'SELECT total_points FROM %%STATPOINTS%% WHERE id_owner = :userId AND stat_type = :statType',
                [
                    ':userId' => $USER['id'],
                    ':statType' => 1,
                ],
            ) ?: ['total_points' => 0];
        } catch (Exception $e) {
            $USER['total_points'] = 0;
        }

        foreach ($memberListResult as $memberListRow) {
            $IsNoobProtec = $this->playerUtil()->checkNoobProtection($USER, $memberListRow);
            $Class = $this->playerUtil()->userStatus($memberListRow, $IsNoobProtec);

            if ($this->allianceData['ally_owner'] == $memberListRow['id']) {
                $memberListRow['ally_rankName'] = empty($this->allianceData['ally_owner_range'])
                    ? $LNG['al_founder_rank_text'] : $this->allianceData['ally_owner_range'];
            } elseif ($memberListRow['ally_rank_id'] != 0 && isset($rankList[$memberListRow['ally_rank_id']])) {
                $memberListRow['ally_rankName'] = $rankList[$memberListRow['ally_rank_id']];
            } else {
                $memberListRow['ally_rankName'] = $LNG['al_new_member_rank_text'];
            }

            $memberList[$memberListRow['id']] = [
                'class' => $Class,
                'username' => $memberListRow['username'],
                'galaxy' => $memberListRow['galaxy'],
                'system' => $memberListRow['system'],
                'planet' => $memberListRow['planet'],
                'register_time' => $memberListRow['ally_register_time'],
                'points' => $memberListRow['total_points'],
                'rankName' => $memberListRow['ally_rankName'],
                'onlinetime' => floor(($this->state->runtime->timestamp - $memberListRow['onlinetime']) / 60),
            ];
        }

        $this->assign([
            'memberList' => $memberList,
            'mainList' => $this->getMainMember(),
            'wingList' => $this->getWingMember(),
            'al_users_list' => sprintf($LNG['al_users_list'], count($memberList)),
            'timezone' => $USER['timezone'],
            'ShortStatus' => [
                'vacation' => $LNG['gl_short_vacation'],
                'banned' => $LNG['gl_short_ban'],
                'inactive' => $LNG['gl_short_inactive'],
                'longinactive' => $LNG['gl_short_long_inactive'],
                'noob' => $LNG['gl_short_newbie'],
                'strong' => $LNG['gl_short_strong'],
                'enemy' => $LNG['gl_short_enemy'],
                'friend' => $LNG['gl_short_friend'],
                'member' => $LNG['gl_short_member'],
            ],
        ]);

        return $this->render('game/page.alliance.memberList.html.twig');
    }

    private function getMainMember()
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $db = $this->db;

        $sql = "SELECT a.id, a.ally_name, a.ally_tag, ally_owner, ally_owner_range FROM %%DIPLO%% d
            JOIN %%ALLIANCE%% a ON a.id = d.owner_1
            WHERE d.owner_2 = :AllianceID AND d.level = 1 AND accept = 1;";
        $mainData = $db->select($sql, [
            ':AllianceID' => $this->allianceData['id'],
        ]);

        $mainList = [];

        if ($mainData) {
            foreach ($mainData as $main) {
                $sql = "SELECT rankID, rankName FROM %%ALLIANCE_RANK%% WHERE allianceId = :AllianceID";
                $rankResult = $db->select($sql, [
                    ':AllianceID' => $main['id'],
                ]);

                foreach ($rankResult as $rankRow) {
                    $rankList[$rankRow['rankID']] = $rankRow['rankName'];
                }

                $mainMemberList = [];

                $sql = "SELECT DISTINCT u.id, u.username,u.galaxy, u.system, u.planet, u.banaday, u.urlaubs_modus, 
                    u.ally_register_time, u.onlinetime, u.ally_rank_id, s.total_points, u.rebuild_protection, u.rebuild_protection_attackers
                    FROM %%USERS%% u
                    LEFT JOIN %%STATPOINTS%% as s ON s.stat_type = '1' AND s.id_owner = u.id 
                    WHERE ally_id = :AllianceID;";

                $mainListResult = $db->select($sql, [
                    ':AllianceID' => $main['id'],
                ]);

                try {
                    $USER += $db->selectSingle(
                        'SELECT total_points FROM %%STATPOINTS%% WHERE id_owner = :userId AND stat_type = :statType',
                        [
                            ':userId' => $USER['id'],
                            ':statType' => 1,
                        ],
                    ) ?: ['total_points' => 0];
                } catch (Exception $e) {
                    $USER['total_points'] = 0;
                }

                foreach ($mainListResult as $mainListRow) {
                    $IsNoobProtec = $this->playerUtil()->checkNoobProtection($USER, $mainListRow);
                    $Class = $this->playerUtil()->userStatus($mainListRow, $IsNoobProtec);

                    if ($main['ally_owner'] == $mainListRow['id']) {
                        $mainListRow['ally_rankName'] = empty($main['ally_owner_range'])
                            ? $LNG['al_founder_rank_text'] : $main['ally_owner_range'];
                    } elseif ($mainListRow['ally_rank_id'] != 0 && isset($rankList[$mainListRow['ally_rank_id']])) {
                        $mainListRow['ally_rankName'] = $rankList[$mainListRow['ally_rank_id']];
                    } else {
                        $mainListRow['ally_rankName'] = $LNG['al_new_member_rank_text'];
                    }

                    $mainMemberList[$mainListRow['id']] = [
                        'class' => $Class,
                        'username' => $mainListRow['username'],
                        'galaxy' => $mainListRow['galaxy'],
                        'system' => $mainListRow['system'],
                        'planet' => $mainListRow['planet'],
                        'register_time' => $mainListRow['ally_register_time'],
                        'points' => $mainListRow['total_points'],
                        'rankName' => $mainListRow['ally_rankName'],
                        'onlinetime' => floor(($this->state->runtime->timestamp - $mainListRow['onlinetime']) / 60),
                    ];
                }
                $mainList[$main['id']]['header'] = sprintf($LNG['al_users_list_main'], count($mainMemberList), '[' . $main['ally_tag'] . '] ' . $main['ally_name']);
                $mainList[$main['id']]['mainData'] = $main;
                $mainList[$main['id']]['memberList'] = $mainMemberList;
            }
        }
        return $mainList;
    }

    private function getWingMember()
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $db = $this->db;

        $sql = "SELECT a.id, a.ally_name, a.ally_tag, ally_owner, ally_owner_range FROM %%DIPLO%% d
            JOIN %%ALLIANCE%% a ON a.id = d.owner_2
            WHERE d.owner_1 = :AllianceID AND d.level = 1 AND accept = 1;";
        $wingData = $db->select($sql, [
            ':AllianceID' => $this->allianceData['id'],
        ]);

        $wingList = [];

        if ($wingData) {
            foreach ($wingData as $wing) {
                $sql = "SELECT rankID, rankName FROM %%ALLIANCE_RANK%% WHERE allianceId = :AllianceID";
                $rankResult = $db->select($sql, [
                    ':AllianceID' => $wing['id'],
                ]);

                foreach ($rankResult as $rankRow) {
                    $rankList[$rankRow['rankID']] = $rankRow['rankName'];
                }

                $wingMemberList = [];

                $sql = "SELECT DISTINCT u.id, u.username,u.galaxy, u.system, u.planet, u.banaday, u.urlaubs_modus, 
                    u.ally_register_time, u.onlinetime, u.ally_rank_id, s.total_points, u.rebuild_protection, u.rebuild_protection_attackers
                    FROM %%USERS%% u 
                    LEFT JOIN %%STATPOINTS%% as s ON s.stat_type = '1' AND s.id_owner = u.id 
                    WHERE ally_id = :AllianceID;";
                $wingListResult = $db->select($sql, [
                    ':AllianceID' => $wing['id'],
                ]);

                try {
                    $USER += $db->selectSingle(
                        'SELECT total_points FROM %%STATPOINTS%% WHERE id_owner = :userId AND stat_type = :statType',
                        [
                            ':userId' => $USER['id'],
                            ':statType' => 1,
                        ],
                    ) ?: ['total_points' => 0];
                } catch (Exception $e) {
                    $USER['total_points'] = 0;
                }

                foreach ($wingListResult as $wingListRow) {
                    $IsNoobProtec = $this->playerUtil()->checkNoobProtection($USER, $wingListRow);
                    $Class = $this->playerUtil()->userStatus($wingListRow, $IsNoobProtec);

                    if ($wing['ally_owner'] == $wingListRow['id']) {
                        $wingListRow['ally_rankName'] = empty($wing['ally_owner_range'])
                            ? $LNG['al_founder_rank_text'] : $wing['ally_owner_range'];
                    } elseif ($wingListRow['ally_rank_id'] != 0 && isset($rankList[$wingListRow['ally_rank_id']])) {
                        $wingListRow['ally_rankName'] = $rankList[$wingListRow['ally_rank_id']];
                    } else {
                        $wingListRow['ally_rankName'] = $LNG['al_new_member_rank_text'];
                    }

                    $wingMemberList[$wingListRow['id']] = [
                        'class' => $Class,
                        'username' => $wingListRow['username'],
                        'galaxy' => $wingListRow['galaxy'],
                        'system' => $wingListRow['system'],
                        'planet' => $wingListRow['planet'],
                        'register_time' => $wingListRow['ally_register_time'],
                        'points' => $wingListRow['total_points'],
                        'rankName' => $wingListRow['ally_rankName'],
                        'onlinetime' => floor(($this->state->runtime->timestamp - $wingListRow['onlinetime']) / 60),
                    ];
                }
                $wingList[$wing['id']]['header'] = sprintf($LNG['al_users_list_wing'], count($wingMemberList), '[' . $wing['ally_tag'] . '] ' . $wing['ally_name']);
                $wingList[$wing['id']]['wingData'] = $wing;
                $wingList[$wing['id']]['memberList'] = $wingMemberList;
            }
        }
        return $wingList;
    }

    public function close()
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $db = $this->db;

        // the owner has to transfer or close the alliance, otherwise it would stay without a manager
        if (!$this->hasAlliance || $this->allianceData['ally_owner'] == $USER['id']) {
            $this->redirectToHome();
        }

        $receivers = $this->getMessageReceivers($this->allianceData['id'], false, true);

        foreach ($receivers as $receiver) {
            if ($receiver['id'] == $USER['id']) {
                continue;
            }
            $lang = $this->playerUtil()->getLanguage($receiver['lang']);

            $applyMessage = sprintf(
                $lang['al_leaving_msg'],
                $USER['id'],
                $USER['username'],
                $USER['username'],
            );

            $this->playerUtil()->sendMessage(
                $receiver['id'],
                0,
                $lang['al_alliance'],
                MESSAGE_TYPE_ALLIANCE,
                $lang['al_leaving'],
                $applyMessage,
                $this->state->runtime->timestamp,
            );
        }

        $sql = "UPDATE %%USERS%% SET ally_id = 0, ally_register_time = 0, ally_rank_id = 0 WHERE id = :UserID;";
        $db->update($sql, [
            ':UserID' => $USER['id'],
        ]);

        $sql = "UPDATE %%STATPOINTS%% SET id_ally = 0 WHERE id_owner = :UserID AND stat_type = 1;";
        $db->update($sql, [
            ':UserID' => $USER['id'],
        ]);

        $sql = "UPDATE %%ALLIANCE%% SET ally_members = (SELECT COUNT(*) FROM %%USERS%% WHERE ally_id = :AllianceID)"
            . " WHERE id = :AllianceID;";
        $db->update($sql, [
            ':AllianceID' => $this->allianceData['id'],
        ]);

        $this->printMessage($this->htmlText($LNG['al_leave_sucess'], $this->allianceData['ally_name']), [[
            'label' => $LNG['sys_back'],
            'url' => '?page=alliance',
        ]]);
    }

    public function circular(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        if (!$this->rights['ROUNDMAIL']) {
            $this->redirectToHome();
        }

        $action = $this->http()->_GP('action', '');

        if ($action == "send") {
            $rankId = $this->http()->_GP('rankID', 0);
            $subject = $this->http()->_GP('subject', '', true);
            $text = $this->http()->_GP('text', $LNG['mg_no_subject'], true);

            if (empty($text)) {
                $this->sendJSON(['message' => $LNG['mg_empty_text'], 'error' => true]);
            }

            $db = $this->db;

            if ($rankId == 0) {
                $sql = 'SELECT id, username FROM %%USERS%% WHERE ally_id = :AllianceID;';
                $sendUsersResult = $db->select($sql, [
                    ':AllianceID' => $this->allianceData['id'],
                ]);
            } else {
                $sql = 'SELECT id, username FROM %%USERS%% WHERE ally_id = :AllianceID AND ally_rank_id = :RankID;';
                $sendUsersResult = $db->select($sql, [
                    ':AllianceID' => $this->allianceData['id'],
                    ':RankID' => $rankId,
                ]);
            }

            $sendList = $LNG['al_circular_sended'];
            $title = $LNG['al_circular_alliance'] . $this->allianceData['ally_tag'];
            $text = sprintf($LNG['al_circular_front_text'], $USER['username']) . "\r\n" . $text;

            $receivers = [];
            foreach ($sendUsersResult as $sendUsersRow) {
                $receivers[] = $sendUsersRow['id'];
                $sendList .= "\n" . $sendUsersRow['username'];
            }
            $this->playerUtil()->sendMessage(
                $receivers,
                $USER['id'],
                $title,
                MESSAGE_TYPE_ALLIANCE,
                $subject,
                makebr($text),
                $this->state->runtime->timestamp,
            );

            $this->sendJSON(['message' => $sendList, 'error' => false]);
        }

        $this->setWindow('popup');
        $RangeList[] = $LNG['al_all_players'];

        if (is_array($this->ranks)) {
            foreach ($this->ranks as $id => $array) {
                $RangeList[$id + 1] = $array['name'];
            }
        }

        $this->assign([
            'RangeList' => $RangeList,
        ]);

        return $this->render('game/page.alliance.circular.html.twig');
    }

    public function admin(): ?Response
    {
        $LNG = & $this->state->LNG;

        $action = $this->http()->_GP('action', 'overview');
        $methodName = 'admin' . ucwords($action);

        if (!is_callable([$this, $methodName])) {
            (new ErrorController())->withGameServicesOf($this)->showError($LNG['page_doesnt_exist']);
        }

        return $this->{$methodName}();
    }

    protected function adminOverview(): Response
    {
        if (!$this->hasAlliance || !$this->rights['ADMIN']) {
            $this->redirectToHome();
        }

        $LNG = & $this->state->LNG;
        $send = $this->http()->_GP('send', 0);
        $textMode = $this->http()->_GP('textMode', 'external');

        if ($send) {
            $db = $this->db;

            $this->allianceData['ally_owner_range'] = mb_substr($this->http()->_GP('owner_range', '', true), 0, 32);
            $this->allianceData['ally_web'] = filter_var($this->http()->_GP('web', ''), FILTER_VALIDATE_URL);
            $this->allianceData['ally_image'] = filter_var($this->http()->_GP('image', ''), FILTER_VALIDATE_URL);
            $this->allianceData['ally_request_notallow'] = $this->http()->_GP('request_notallow', 0);
            $this->allianceData['ally_max_members'] = max(
                $this->http()->_GP('ally_max_members', ''),
                $this->allianceData['ally_members'],
            );
            $this->allianceData['ally_request_min_points'] = $this->http()->_GP('request_min_points', 0);
            $this->allianceData['ally_stats'] = $this->http()->_GP('stats', 0);
            $this->allianceData['ally_diplo'] = $this->http()->_GP('diplo', 0);

            $new_ally_tag = $this->http()->_GP('ally_tag', $this->allianceData['ally_tag'], UTF8_SUPPORT);
            $new_ally_name = $this->http()->_GP('ally_name', $this->allianceData['ally_name'], UTF8_SUPPORT);

            if (mb_strlen($new_ally_tag) > 8 || mb_strlen($new_ally_name) > 30) {
                $this->printMessage($LNG['al_name_tag_too_long'], [[
                    'label' => $LNG['sys_back'],
                    'url' => '?page=alliance&mode=admin',
                ]]);
            }

            if (!$this->playerUtil()->isNameValid($new_ally_tag) || !$this->playerUtil()->isNameValid($new_ally_name)) {
                $this->printMessage($LNG['al_newname_specialchar'], [[
                    'label' => $LNG['sys_back'],
                    'url' => '?page=alliance&mode=admin',
                ]]);
            }

            if (!empty($new_ally_tag) && $this->allianceData['ally_tag'] != $new_ally_tag) {
                $sql = "SELECT COUNT(*) as count FROM %%ALLIANCE%% WHERE ally_universe = :universe"
                    . " AND ally_tag = :NewAllianceTag;";
                $allianceCount = $db->selectSingle($sql, [
                    ':universe' => $this->universe()->current(),
                    ':NewAllianceTag' => $new_ally_tag,
                ], 'count');

                if ($allianceCount != 0) {
                    $this->printMessage($this->htmlText($LNG['al_already_exists'], $new_ally_tag), [[
                        'label' => $LNG['sys_back'],
                        'url' => 'game?page=alliance&mode=admin',
                    ]]);
                } else {
                    $this->allianceData['ally_tag'] = $new_ally_tag;
                }
            }

            if (!empty($new_ally_name) && $this->allianceData['ally_name'] != $new_ally_name) {
                $sql = "SELECT COUNT(*) as count FROM %%ALLIANCE%% WHERE ally_universe = :universe"
                    . " AND ally_name = :NewAllianceName;";
                $allianceCount = $db->selectSingle($sql, [
                    ':universe' => $this->universe()->current(),
                    ':NewAllianceName' => $new_ally_name,
                ], 'count');

                if ($allianceCount != 0) {
                    $this->printMessage($this->htmlText($LNG['al_already_exists'], $new_ally_name), [[
                        'label' => $LNG['sys_back'],
                        'url' => 'game?page=alliance&mode=admin',
                    ]]);
                } else {
                    $this->allianceData['ally_name'] = $new_ally_name;
                }
            }

            if (
                $this->allianceData['ally_request_notallow'] != 0
                && $this->allianceData['ally_request_notallow'] != 1
            ) {
                $this->allianceData['ally_request_notallow'] = 0;
            }

            $text = $this->http()->_GP('text', '', true);
            $textMode = $this->http()->_GP('textMode', 'external');

            $textSQL = "";

            switch ($textMode) {
                case 'external':
                    $textSQL = "ally_description = :text, ";
                    break;
                case 'internal':
                    $textSQL = "ally_text = :text, ";
                    break;
                case 'apply':
                    $textSQL = "ally_request = :text, ";
                    break;
            }

            $sql = "UPDATE %%ALLIANCE%% SET
			" . $textSQL . "
			ally_tag = :AllianceTag,
			ally_name = :AllianceName,
			ally_owner_range = :AllianceOwnerRange,
			ally_image = :AllianceImage,
			ally_web = :AllianceWeb,
			ally_request_notallow = :AllianceRequestNotAllow,
			ally_max_members = :AllianceMaxMember,
			ally_request_min_points = :AllianceRequestMinPoints,
			ally_stats = :AllianceStats,
			ally_diplo = :AllianceDiplo
			WHERE id = :AllianceID;";

            $db->update($sql, [
                ':AllianceTag' => $this->allianceData['ally_tag'],
                ':AllianceName' => $this->allianceData['ally_name'],
                ':AllianceOwnerRange' => $this->allianceData['ally_owner_range'],
                ':AllianceImage' => $this->allianceData['ally_image'],
                ':AllianceWeb' => $this->allianceData['ally_web'],
                ':AllianceRequestNotAllow' => $this->allianceData['ally_request_notallow'],
                ':AllianceMaxMember' => $this->allianceData['ally_max_members'],
                ':AllianceRequestMinPoints' => $this->allianceData['ally_request_min_points'],
                ':AllianceStats' => $this->allianceData['ally_stats'],
                ':AllianceDiplo' => $this->allianceData['ally_diplo'],
                ':AllianceID' => $this->allianceData['id'],
                ':text' => $text,
            ]);
        } else {
            switch ($textMode) {
                case 'internal':
                    $text = $this->allianceData['ally_text'];
                    break;
                case 'apply':
                    $text = $this->allianceData['ally_request'];
                    break;
                default:
                    $text = $this->allianceData['ally_description'];
                    break;
            }
        }

        $this->assign([
            'RequestSelector' => [0 => $LNG['al_requests_allowed'], 1 => $LNG['al_requests_not_allowed']],
            'YesNoSelector' => [1 => $LNG['al_go_out_yes'], 0 => $LNG['al_go_out_no']],
            'textMode' => $textMode,
            'text' => $text,
            'ally_tag' => $this->allianceData['ally_tag'],
            'ally_name' => $this->allianceData['ally_name'],
            'ally_web' => $this->allianceData['ally_web'],
            'ally_image' => $this->allianceData['ally_image'],
            'ally_request_notallow' => $this->allianceData['ally_request_notallow'],
            'ally_members' => $this->allianceData['ally_members'],
            'ally_max_members' => $this->allianceData['ally_max_members'],
            'ally_request_min_points' => $this->allianceData['ally_request_min_points'],
            'ally_owner_range' => $this->allianceData['ally_owner_range'],
            'ally_stats_data' => $this->allianceData['ally_stats'],
            'ally_diplo_data' => $this->allianceData['ally_diplo'],
        ]);

        return $this->render('game/page.alliance.admin.overview.html.twig');
    }

    protected function adminClose()
    {
        $USER = & $this->state->USER;
        if ($this->allianceData['ally_owner'] == $USER['id']) {
            $db = $this->db;

            $sql = "UPDATE %%USERS%% SET ally_id = '0' WHERE ally_id = :AllianceID;";
            $db->update($sql, [
                ':AllianceID' => $this->allianceData['id'],
            ]);

            $sql = "UPDATE %%STATPOINTS%% SET id_ally = '0' WHERE id_ally = :AllianceID;";
            $db->update($sql, [
                ':AllianceID' => $this->allianceData['id'],
            ]);

            $sql = "DELETE FROM %%STATPOINTS%% WHERE id_owner = :AllianceID AND stat_type = 2;";
            $db->delete($sql, [
                ':AllianceID' => $this->allianceData['id'],
            ]);
            $sql = "DELETE FROM %%STATPOINTS_HISTORY%% WHERE id_owner = :AllianceID AND stat_type = 2;";
            $db->delete($sql, [
                ':AllianceID' => $this->allianceData['id'],
            ]);

            $sql = "DELETE FROM %%ALLIANCE%% WHERE id = :AllianceID;";
            $db->delete($sql, [
                ':AllianceID' => $this->allianceData['id'],
            ]);

            $sql = "DELETE FROM %%ALLIANCE_REQUEST%% WHERE allianceId = :AllianceID;";
            $db->delete($sql, [
                ':AllianceID' => $this->allianceData['id'],
            ]);

            $sql = "DELETE FROM %%ALLIANCE_RANK%% WHERE allianceID = :AllianceID;";
            $db->delete($sql, [
                ':AllianceID' => $this->allianceData['id'],
            ]);

            $sql = "DELETE FROM %%DIPLO%% WHERE owner_1 = :AllianceID OR owner_2 = :AllianceID;";
            $db->delete($sql, [
                ':AllianceID' => $this->allianceData['id'],
            ]);
        }

        $this->redirectToHome();
    }

    protected function adminTransfer(): Response
    {
        $USER = & $this->state->USER;

        if ($this->allianceData['ally_owner'] != $USER['id']) {
            $this->redirectToHome();
        }

        $db = $this->db;

        $postleader = $this->http()->_GP('newleader', 0);
        if (!empty($postleader)) {
            // the new leader must be another member of this alliance with the TRANSFER right
            $sql = "SELECT u.ally_rank_id FROM %%USERS%% u INNER JOIN %%ALLIANCE_RANK%% r"
                . " ON r.rankID = u.ally_rank_id AND r.allianceID = u.ally_id AND r.TRANSFER = 1"
                . " WHERE u.id = :LeaderID AND u.ally_id = :allianceId AND u.id != :ownerId;";
            $Rank = $db->selectSingle($sql, [
                ':LeaderID' => (int) $postleader,
                ':allianceId' => $this->allianceData['id'],
                ':ownerId' => $USER['id'],
            ]);

            if (!$Rank) {
                $this->redirectToHome();
            }

            $sql = "UPDATE %%USERS%% SET ally_rank_id = :AllyRank WHERE id = :UserID;";
            $db->update($sql, [
                ':UserID' => $USER['id'],
                ':AllyRank' => $Rank['ally_rank_id'],
            ]);

            $sql = "UPDATE %%USERS%% SET ally_rank_id = 0 WHERE id = :LeaderID;";
            $db->update($sql, [
                ':LeaderID' => $postleader,
            ]);

            $sql = "UPDATE %%ALLIANCE%% SET ally_owner = :LeaderID WHERE id = :AllianceID;";
            $db->update($sql, [
                ':LeaderID' => $postleader,
                ':AllianceID' => $this->allianceData['id'],
            ]);

            $this->redirectToHome();
        } else {
            $sql = "SELECT u.id, r.rankName, u.username FROM %%USERS%% u INNER JOIN %%ALLIANCE_RANK%% r"
                . " ON r.rankID = u.ally_rank_id AND r.TRANSFER = 1 WHERE u.ally_id = :allianceId"
                . " AND id != :allianceOwner;";
            $transferUserResult = $db->select($sql, [
                ':allianceOwner' => $this->allianceData['ally_owner'],
                ':allianceId' => $this->allianceData['id'],
            ]);

            $transferUserList = [];

            foreach ($transferUserResult as $transferUserRow) {
                $transferUserList[$transferUserRow['id']] = $transferUserRow['username'] . " ["
                    . $transferUserRow['rankName'] . "]";
            }

            $this->assign([
                'transferUserList' => $transferUserList,
            ]);

            return $this->render('game/page.alliance.admin.transfer.html.twig');
        }
    }

    protected function adminManageApply(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        if (!$this->rights['SEEAPPLY'] || !$this->rights['MANAGEAPPLY']) {
            $this->redirectToHome();
        }

        $db = $this->db;

        $sql = "SELECT applyID, u.username, r.time FROM %%ALLIANCE_REQUEST%% r INNER JOIN %%USERS%% u"
            . " ON r.userId = u.id WHERE r.allianceId = :allianceId;";
        $applyResult = $db->select($sql, [
            ':allianceId' => $this->allianceData['id'],
        ]);

        $applyList = [];

        foreach ($applyResult as $applyRow) {
            $applyList[] = [
                'username' => $applyRow['username'],
                'id' => $applyRow['applyID'],
                'time' => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $applyRow['time'], $USER['timezone']),
            ];
        }

        $this->assign([
            'applyList' => $applyList,
        ]);

        return $this->render('game/page.alliance.admin.manageApply.html.twig');
    }

    protected function adminDetailApply(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        if (!$this->rights['SEEAPPLY'] || !$this->rights['MANAGEAPPLY']) {
            $this->redirectToHome();
        }

        $id = $this->http()->_GP('id', 0);

        $db = $this->db;

        $sql = 'SELECT r.`applyID`, r.`time`, r.`text`, u.`id`, u.`register_time`, u.`onlinetime`
            FROM %%ALLIANCE_REQUEST%% AS r
            LEFT JOIN %%USERS%% AS u ON r.userId = u.id
            WHERE applyID = :applyID AND r.allianceId = :allianceId;';

        $applyDetail = $db->selectSingle($sql, [
            ':applyID' => $id,
            ':allianceId' => $this->allianceData['id'],
        ]);

        if (empty($applyDetail)) {
            $this->printMessage($LNG['al_apply_not_exists'], [[
                'label' => $LNG['sys_back'],
                'url' => 'game?page=alliance&mode=admin&action=manageApply',
            ]]);
        }

        $playerCard = (new PlayerCardController())->withGameServicesOf($this);
        $playerCard->initialize();
        $targetUserId = $applyDetail['id'];
        $stats = $playerCard->get_stats($targetUserId);
        $showPlayer = true;
        $showTotal = true;
        $showBuild = $this->playerUtil()->spyTechAllowed(2, $targetUserId, $USER);
        $showTech = $this->playerUtil()->spyTechAllowed(4, $targetUserId, $USER);
        $showDef = $this->playerUtil()->spyTechAllowed(6, $targetUserId, $USER);
        $showFleet = $this->playerUtil()->spyTechAllowed(6, $targetUserId, $USER);
        $showBattle = $this->playerUtil()->spyTechAllowed(6, $targetUserId, $USER);

        $player_data = $playerCard->get_player_data($stats, $showPlayer);
        $total_data = $playerCard->get_total_data($stats, $showTotal);
        $build_data = $playerCard->get_build_data($stats, $showBuild);
        $tech_data = $playerCard->get_tech_data($stats, $showTech);
        $def_data = $playerCard->get_def_data($stats, $showDef);
        $fleet_data = $playerCard->get_fleet_data($stats, $showFleet);
        $battle_stats = $playerCard->get_battle_stats($stats, $showBattle);
        $units = $playerCard->get_units($stats, $showBattle);
        $real_units = $playerCard->get_real_units($stats, $showBattle);

        $applyDetail['text'] = $this->multilineHtml($applyDetail['text']);

        $this->assign([
            'applyDetail'   => $applyDetail,
            'name'			=> $player_data['username'],
            'homeplanet'	=> $player_data['planetname'],
            'galaxy'		=> $player_data['galaxy'],
            'system'		=> $player_data['system'],
            'planet'		=> $player_data['planet'],
            'apply_time'    => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $applyDetail['time'], $USER['timezone']),
            'register_time' => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $applyDetail['register_time'], $USER['timezone']),
            'onlinetime'    => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $applyDetail['onlinetime'], $USER['timezone']),
            'playerdestory' => sprintf($LNG['pl_destroy'], $player_data['username']),
            'realdestory'   => sprintf($LNG['pl_destroy_real'], $player_data['username']),
            'tech_rank'     => $this->viewHelpers()->prettyNumber($tech_data['tech_rank']),
            'tech_points'   => $this->viewHelpers()->prettyNumber($tech_data['tech_points']),
            'build_rank'    => $this->viewHelpers()->prettyNumber($build_data['build_rank']),
            'build_points'  => $this->viewHelpers()->prettyNumber($build_data['build_points']),
            'defs_rank'     => $this->viewHelpers()->prettyNumber($def_data['defs_rank']),
            'defs_points'   => $this->viewHelpers()->prettyNumber($def_data['defs_points']),
            'fleet_rank'    => $this->viewHelpers()->prettyNumber($fleet_data['fleet_rank']),
            'fleet_points'  => $this->viewHelpers()->prettyNumber($fleet_data['fleet_points']),
            'total_rank'    => $this->viewHelpers()->prettyNumber($total_data['total_rank']),
            'total_points'  => $this->viewHelpers()->prettyNumber($total_data['total_points']),
            'wons'          => $this->viewHelpers()->prettyNumber($battle_stats['wons']),
            'loos'          => $this->viewHelpers()->prettyNumber($battle_stats['loos']),
            'draws'         => $this->viewHelpers()->prettyNumber($battle_stats['draws']),
            'kbmetal'       => $this->viewHelpers()->prettyNumber($units['kbmetal']),
            'kbcrystal'     => $this->viewHelpers()->prettyNumber($units['kbcrystal']),
            'lostunits'     => $this->viewHelpers()->prettyNumber($units['lostunits']),
            'desunits'      => $this->viewHelpers()->prettyNumber($units['desunits']),
            'realdesunits'  => $this->viewHelpers()->prettyNumber($real_units["destroyed"]),
            'reallostunits' => $this->viewHelpers()->prettyNumber($real_units["lost"]),
            'realmetal'     => $this->viewHelpers()->prettyNumber($real_units["debris_metal"]),
            'realcrystal'   => $this->viewHelpers()->prettyNumber($real_units["debris_crystal"]),
            'totalfights'   => $this->viewHelpers()->prettyNumber($battle_stats['totalfights']),
            'siegprozent'   => round($battle_stats["siegprozent"], 2),
            'loosprozent'   => round($battle_stats["loosprozent"], 2),
            'drawsprozent'  => round($battle_stats["drawsprozent"], 2),
            'tech_percent'  => $tech_data['tech_percent'] == 0 ? '0,00' : round($tech_data['tech_percent'], 2),
            'build_percent' => $build_data['build_percent'] == 0 ? '0,00' : round($build_data['build_percent'], 2),
            'defs_percent'  => $def_data['defs_percent'] == 0 ? '0,00' : round($def_data['defs_percent'], 2),
            'fleet_percent' => $fleet_data['fleet_percent'] == 0 ? '0,00' : round($fleet_data['fleet_percent'], 2),
            'showBuild'     => $showBuild,
            'showTech'      => $showTech,
            'showDef'       => $showDef,
            'showFleet'     => $showFleet,
            'showBattle'    => $showBattle,
        ]);

        return $this->render('game/page.alliance.admin.detailApply.html.twig');
    }

    protected function adminSendAnswerToApply()
    {
        $USER = & $this->state->USER;
        if (!$this->rights['SEEAPPLY'] || !$this->rights['MANAGEAPPLY']) {
            $this->redirectToHome();
        }

        $db = $this->db;

        $text = makebr($this->http()->_GP('text', '', true));
        $answer = $this->http()->_GP('answer', '');
        $applyID = $this->http()->_GP('id', 0);

        $sql = "SELECT userId FROM %%ALLIANCE_REQUEST%% WHERE applyID = :applyID AND allianceId = :allianceId;";
        $userId = $db->selectSingle($sql, [
            ':applyID' => $applyID,
            ':allianceId' => $this->allianceData['id'],
        ], 'userId');

        // only if alliance request still exist
        if ($userId) {
            $LNG = $this->playerUtil()->getLanguage(null, $userId);
            if ($answer == 'yes') {
                $db->startTransaction();
                // The applicant may have joined another alliance since applying
                $sql = "SELECT ally_id FROM %%USERS%% WHERE id = :userId FOR UPDATE;";
                $applicantAllianceId = $db->selectSingle($sql, [':userId' => $userId], 'ally_id');
                if ($applicantAllianceId != 0) {
                    $sql = "DELETE FROM %%ALLIANCE_REQUEST%% WHERE applyID = :applyID";
                    $db->delete($sql, [
                        ':applyID' => $applyID,
                    ]);
                    $db->commit();
                    $this->redirectTo('game?page=alliance&mode=admin&action=manageApply');
                }
                $sql = "SELECT COUNT(*) as member_count FROM %%USERS%% WHERE ally_id = :allianceId FOR UPDATE";
                $memberCount = $db->selectSingle($sql, [':allianceId' => $this->allianceData['id']], 'member_count');
                if ($memberCount >= $this->allianceData['ally_max_members']) {
                    $db->rollback();
                    $answer = 'no';
                    $text = $LNG['al_alliance_full'];
                    $subject = $LNG['al_you_was_declined'] . $this->allianceData['ally_name'];

                    $sql = "DELETE FROM %%ALLIANCE_REQUEST%% WHERE applyID = :applyID AND allianceId = :allianceId";
                    $db->delete($sql, [
                        ':applyID' => $applyID,
                        ':allianceId' => $this->allianceData['id'],
                    ]);
                } else {
                    $sql = "DELETE FROM %%ALLIANCE_REQUEST%% WHERE applyID = :applyID AND allianceId = :allianceId";
                    $db->delete($sql, [
                        ':applyID' => $applyID,
                        ':allianceId' => $this->allianceData['id'],
                    ]);

                    $sql = "UPDATE %%USERS%% SET ally_id = :allianceId, ally_register_time = :time, ally_rank_id = 0
                        WHERE id = :userId;";
                    $db->update($sql, [
                        ':allianceId' => $this->allianceData['id'],
                        ':time' => $this->state->runtime->timestamp,
                        ':userId' => $userId,
                    ]);

                    $sql = "UPDATE %%STATPOINTS%% SET id_ally = :allianceId WHERE id_owner = :userId AND stat_type = 1;";
                    $db->update($sql, [
                        ':allianceId' => $this->allianceData['id'],
                        ':userId' => $userId,
                    ]);

                    $sql = "UPDATE %%ALLIANCE%% SET ally_members = ally_members + 1 WHERE id = :allianceId;";
                    $db->update($sql, [
                        ':allianceId' => $this->allianceData['id'],
                    ]);

                    $db->commit();
                    $text = $LNG['al_hi_the_alliance'] . $this->allianceData['ally_name'] . $LNG['al_has_accepted'] . $text;
                    $subject = $LNG['al_you_was_acceted'] . $this->allianceData['ally_name'];
                }
            } else {
                $sql = "DELETE FROM %%ALLIANCE_REQUEST%% WHERE applyID = :applyID AND allianceId = :allianceId";
                $db->delete($sql, [
                    ':applyID' => $applyID,
                    ':allianceId' => $this->allianceData['id'],
                ]);

                $text = $LNG['al_hi_the_alliance'] . $this->allianceData['ally_name'] . $LNG['al_has_declined'] . $text;
                $subject = $LNG['al_you_was_declined'] . $this->allianceData['ally_name'];
            }

            $senderName = $LNG['al_the_alliance'] . $this->allianceData['ally_name'] . ' ['
                . $this->allianceData['ally_tag'] . ']';
            $this->playerUtil()->sendMessage($userId, $USER['id'], $senderName, MESSAGE_TYPE_ALLIANCE, $subject, $text, $this->state->runtime->timestamp);

            // confirmation for the manager (own language, $LNG above is the applicant's)
            $managerLNG = & $this->state->LNG;
            $this->printMessage($answer == 'yes' ? $managerLNG['al_apply_accepted'] : $managerLNG['al_apply_declined'], [[
                'label' => $managerLNG['sys_back'],
                'url' => 'game?page=alliance&mode=admin&action=manageApply',
            ]]);
        }
        $this->redirectTo('game?page=alliance&mode=admin&action=manageApply');
    }

    protected function adminPermissions(): Response
    {
        if (!$this->rights['RANKS']) {
            $this->redirectToHome();
        }
        $USER = & $this->state->USER;

        $sql = "SELECT * FROM %%ALLIANCE_RANK%% WHERE allianceId = :allianceId;";
        $rankResult = $this->db->select($sql, [
            ':allianceId' => $this->allianceData['id'],
        ]);

        $rankList = [];
        $changeableRanks = [];
        foreach ($rankResult as $rankRow) {
            $rankList[$rankRow['rankID']] = $rankRow;
            $changeable = true;
            foreach ($this->availableRanks as $rankId => $rankName) {
                if (!$this->rights[$rankName] && $rankRow[$rankName] == 1) {
                    $changeable = false;
                    break;
                }
            }
            $changeableRanks[$rankRow['rankID']] = $USER['ally_rank_id'] != $rankRow['rankID'] && $changeable;
        }

        $availableRanks = [];
        foreach ($this->availableRanks as $rankId => $rankName) {
            if ($this->rights[$rankName]) {
                $availableRanks[$rankId] = $rankName;
            }
        }

        $this->assign([
            'rankList' => $rankList,
            'ownRights' => $this->rights,
            'ownRankId' => $USER['ally_rank_id'],
            'availableRanks' => $availableRanks,
            'changeableRanks' => $changeableRanks,
        ]);

        return $this->render('game/page.alliance.admin.permissions.html.twig');
    }

    protected function adminPermissionsSend()
    {
        $LNG = & $this->state->LNG;
        if (!$this->rights['RANKS']) {
            $this->redirectToHome();
        }

        $newRank = $this->http()->_GP('newrank', [], true);
        $delete = $this->http()->_GP('deleteRank', 0);
        $rankData = $this->http()->_GP('rank', []);

        $db = $this->db;

        if (!empty($newRank['rankName'])) {
            if (!$this->playerUtil()->isNameValid($newRank['rankName']) || mb_strlen($newRank['rankName']) > 32) {
                $this->printMessage($LNG['al_invalid_rank_name'], [[
                    'label' => $LNG['sys_back'],
                    'url' => '?page=alliance&mode=admin&action=permission',
                ]]);
            }

            $sql = 'INSERT INTO %%ALLIANCE_RANK%% SET rankName = :rankName, allianceID = :allianceID';
            $params = [
                ':rankName' => $newRank['rankName'],
                ':allianceID' => $this->allianceData['id'],
            ];

            unset($newRank['rankName']);

            foreach ($newRank as $key => $value) {
                if (isset($this->availableRanks[$key]) && $this->rights[$this->availableRanks[$key]]) {
                    $sql .= ', `' . $this->availableRanks[$key] . '` = :' . $this->availableRanks[$key];
                    $params[':' . $this->availableRanks[$key]] = $value == 1 ? 1 : 0;
                }
            }

            $db->insert($sql, $params);
        } else {
            if (!empty($delete)) {
                $sql = "DELETE FROM %%ALLIANCE_RANK%% WHERE rankID = :rankID AND allianceId = :allianceId;";
                $db->delete($sql, [
                    ':allianceId' => $this->allianceData['id'],
                    ':rankID' => $delete,
                ]);

                $sql = "UPDATE %%USERS%% SET ally_rank_id = 0 WHERE ally_rank_id = :rankID AND ally_id = :allianceId;";
                $db->update($sql, [
                    ':allianceId' => $this->allianceData['id'],
                    ':rankID' => $delete,
                ]);
            } else {
                foreach ($rankData as $rankId => $rowData) {
                    if (
                        !$this->playerUtil()->isNameValid($rowData['rankName'] ?? '')
                        || mb_strlen($rowData['rankName']) > 32
                    ) {
                        $this->printMessage($LNG['al_invalid_rank_name'], [[
                            'label' => $LNG['sys_back'],
                            'url' => '?page=alliance&mode=admin&action=permissions',
                        ]]);
                    }

                    $sql = 'UPDATE %%ALLIANCE_RANK%% SET rankName = :rankName';
                    $params = [
                        ':rankName' => $rowData['rankName'],
                        ':allianceID' => $this->allianceData['id'],
                        ':rankId' => $rankId,
                    ];

                    unset($rowData['rankName']);

                    foreach ($this->availableRanks as $key => $value) {
                        if (isset($this->availableRanks[$key]) && $this->rights[$this->availableRanks[$key]]) {
                            $sql .= ', `' . $this->availableRanks[$key] . '` = :' . $this->availableRanks[$key];
                            $params[':' . $this->availableRanks[$key]] = (isset($rowData[$key])) == 1 ? 1 : 0;
                        }
                    }

                    $sql .= ' WHERE rankID = :rankId AND allianceID = :allianceID';

                    $db->update($sql, $params);
                }
            }
        }

        $this->redirectTo('game?page=alliance&mode=admin&action=permissions');
    }

    protected function adminMembers(): Response
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        if (!$this->rights['MANAGEUSERS']) {
            $this->redirectToHome();
        }

        $db = $this->db;

        $sql = 'SELECT rankID, rankName, ' . implode(', ', $this->availableRanks)
            . ' FROM %%ALLIANCE_RANK%% WHERE allianceId = :allianceId;';
        $rankResult = $db->select($sql, [
            ':allianceId' => $this->allianceData['id'],
        ]);

        $rankList = [$LNG['al_new_member_rank_text']];
        $rankSelectList = $rankList;

        foreach ($rankResult as $rankRow) {
            $hasRankRight = true;
            foreach ($this->availableRanks as $rankName) {
                if (!$this->rights[$rankName] && $rankRow[$rankName] == 1) {
                    $hasRankRight = false;
                    break;
                }
            }

            if ($hasRankRight) {
                $rankSelectList[$rankRow['rankID']] = $rankRow['rankName'];
            }

            $rankList[$rankRow['rankID']] = $rankRow['rankName'];
        }

        $sql = "SELECT DISTINCT u.id, u.username, u.galaxy, u.system, u.planet, u.banaday, u.urlaubs_modus,
            u.ally_register_time, u.onlinetime, u.ally_rank_id, s.total_points, u.rebuild_protection, u.rebuild_protection_attackers
		    FROM %%USERS%% u
		    LEFT JOIN %%STATPOINTS%% as s ON s.stat_type = '1' AND s.id_owner = u.id
		    WHERE ally_id = :allianceId;";

        $memberListResult = $db->select($sql, [
            ':allianceId' => $this->allianceData['id'],
        ]);

        $memberList = [];

        try {
            $USER += $db->selectSingle(
                'SELECT total_points FROM %%STATPOINTS%% WHERE id_owner = :userId AND stat_type = :statType',
                [
                    ':userId' => $USER['id'],
                    ':statType' => 1,
                ],
            );
        } catch (Exception $e) {
            $USER['total_points'] = 0;
        }

        foreach ($memberListResult as $memberListRow) {
            $IsNoobProtec = $this->playerUtil()->checkNoobProtection($USER, $memberListRow);
            $Class = $this->playerUtil()->userStatus($memberListRow, $IsNoobProtec);

            if ($this->allianceData['ally_owner'] == $memberListRow['id']) {
                $memberListRow['ally_rank_id'] = -1;
            }

            $memberList[$memberListRow['id']] = [
                'class' => $Class,
                'username' => $memberListRow['username'],
                'galaxy' => $memberListRow['galaxy'],
                'system' => $memberListRow['system'],
                'planet' => $memberListRow['planet'],
                'register_time' => $memberListRow['ally_register_time'],
                'points' => $memberListRow['total_points'],
                'rankID' => $memberListRow['ally_rank_id'],
                'onlinetime' => floor(($this->state->runtime->timestamp - $memberListRow['onlinetime']) / 60),
                'kickQuestion' => sprintf($LNG['al_kick_player'], $memberListRow['username']),
            ];
        }

        $this->assign([
            'memberList' => $memberList,
            'rankList' => $rankList,
            'rankSelectList' => $rankSelectList,
            'founder' => empty($this->allianceData['ally_owner_range'])
                ? $LNG['al_founder_rank_text'] : $this->allianceData['ally_owner_range'],
            'al_users_list' => sprintf($LNG['al_users_list'], count($memberList)),
            'canKick' => $this->rights['KICK'],
            'ShortStatus' => [
                'vacation' => $LNG['gl_short_vacation'],
                'banned' => $LNG['gl_short_ban'],
                'inactive' => $LNG['gl_short_inactive'],
                'longinactive' => $LNG['gl_short_long_inactive'],
                'noob' => $LNG['gl_short_newbie'],
                'strong' => $LNG['gl_short_strong'],
                'enemy' => $LNG['gl_short_enemy'],
                'friend' => $LNG['gl_short_friend'],
                'member' => $LNG['gl_short_member'],
            ],
        ]);

        return $this->render('game/page.alliance.admin.members.html.twig');
    }

    protected function adminRank()
    {
        $LNG = & $this->state->LNG;
        if (!$this->rights['MANAGEUSERS']) {
            $this->redirectToHome();
        }

        $userRanks = $this->http()->_GP('rank', []);

        $db = $this->db;

        $sql = 'SELECT rankID, ' . implode(', ', $this->availableRanks)
            . ' FROM %%ALLIANCE_RANK%% WHERE allianceID = :allianceId;';
        $rankResult = $db->select($sql, [
            ':allianceId' => $this->allianceData['id'],
        ]);
        $rankList = [];
        $rankList[0] = array_combine($this->availableRanks, array_fill(0, count($this->availableRanks), true));

        foreach ($rankResult as $rankRow) {
            $hasRankRight = true;
            foreach ($this->availableRanks as $rankName) {
                if (!$this->rights[$rankName] && $rankRow[$rankName] == 1) {
                    $hasRankRight = false;
                    break;
                }
            }

            if ($hasRankRight) {
                $rankList[$rankRow['rankID']] = $rankRow;
            }
        }

        foreach ($userRanks as $userId => $rankId) {
            if ($userId == $this->allianceData['ally_owner'] || !isset($rankList[$rankId])) {
                continue;
            }

            $sql = 'UPDATE %%USERS%% SET ally_rank_id = :rankID WHERE id = :userId AND ally_id = :allianceId;';
            $db->update($sql, [
                ':allianceId' => $this->allianceData['id'],
                ':rankID' => (int) $rankId,
                ':userId' => (int) $userId,
            ]);
        }

        $this->sendJSON($LNG['al_saved']);
    }

    protected function adminMembersKick()
    {
        $USER = & $this->state->USER;
        if (!$this->rights['KICK']) {
            $this->redirectToHome();
        }

        $db = $this->db;

        $id = $this->http()->_GP('id', 0);

        $sql = "SELECT ally_id, ally_rank_id FROM %%USERS%% WHERE id = :id;";
        $kickUser = $db->selectSingle($sql, [
            ':id' => $id,
        ]);

        # Check, if user is in alliance, see #205
        if (empty($kickUser['ally_id']) || $kickUser['ally_id'] != $this->allianceData['id']) {
            $this->redirectToHome();
        }

        // the owner cannot be kicked and nobody can kick a member that holds a right they lack
        if ($id == $this->allianceData['ally_owner'] || $id == $USER['id']) {
            $this->redirectToHome();
        }

        if ($this->allianceData['ally_owner'] != $USER['id'] && $kickUser['ally_rank_id'] != 0) {
            $sql = 'SELECT ' . implode(', ', $this->availableRanks)
                . ' FROM %%ALLIANCE_RANK%% WHERE allianceID = :allianceId AND rankID = :rankId;';
            $kickRank = $db->selectSingle($sql, [
                ':allianceId' => $this->allianceData['id'],
                ':rankId' => $kickUser['ally_rank_id'],
            ]);
            foreach ($kickRank ?: [] as $right => $value) {
                if ($value == 1 && !$this->rights[$right]) {
                    $this->redirectToHome();
                }
            }
        }

        $sql = "UPDATE %%USERS%% SET ally_id = 0, ally_register_time = 0, ally_rank_id = 0 WHERE id = :id;";
        $db->update($sql, [
            ':id' => $id,
        ]);

        $sql = "UPDATE %%STATPOINTS%% SET id_ally = 0 WHERE id_owner = :id AND stat_type = 1;";
        $db->update($sql, [
            ':id' => $id,
        ]);

        $sql = "UPDATE %%ALLIANCE%% SET ally_members = (SELECT COUNT(*) FROM %%USERS%% WHERE ally_id = :allianceId)"
            . " WHERE id = :allianceId;";
        $db->update($sql, [
            ':id' => $id,
            ':allianceId' => $this->allianceData['id'],
        ]);

        $this->redirectTo('game?page=alliance&mode=admin&action=members');
    }

    protected function adminDiplomacy(): Response
    {
        if (!$this->rights['DIPLOMATIC']) {
            $this->redirectToHome();
        }

        $db = $this->db;

        $emptyStatus = [
            DIPLO_MAIN => [],
            DIPLO_WING => [],
            DIPLO_ALLIANCE_PACT => [],
            DIPLO_TRADE_PACT => [],
            DIPLO_NON_AGRESSION_PACT => [],
            DIPLO_WAR => [],
            DIPLO_SECRET_ALLIANCE_PACT => [],
            DIPLO_PEACE => [],
        ];
        $diplomaticList = [
            0 => [
                DIPLO_MAIN => [],
                DIPLO_WING => [],
                DIPLO_ALLIANCE_PACT => [],
                DIPLO_TRADE_PACT => [],
                DIPLO_NON_AGRESSION_PACT => [],
                DIPLO_WAR => [],
                DIPLO_SECRET_ALLIANCE_PACT => [],
            ],
            1 => $emptyStatus,
            2 => $emptyStatus,
        ];

        $sql = "SELECT d.id, d.level, d.accept, d.owner_1, d.owner_2, a.ally_name 
            FROM %%DIPLO%% d
            INNER JOIN %%ALLIANCE%% a ON IF(:allianceId = d.owner_1, a.id = d.owner_2, a.id = d.owner_1)
            WHERE owner_1 = :allianceId OR owner_2 = :allianceId;";
        $diplomaticResult = $db->select($sql, [
            ':allianceId' => $this->allianceData['id'],
        ]);

        foreach ($diplomaticResult as $diplomaticRow) {
            if ($diplomaticRow['level'] == DIPLO_WING && $diplomaticRow['owner_2'] == $this->allianceData['id']) {
                $diplomaticRow['level'] = DIPLO_MAIN;
            }
            $own = $diplomaticRow['owner_1'] == $this->allianceData['id'];
            if ($diplomaticRow['accept'] == 1) {
                $diplomaticList[0][$diplomaticRow['level']][$diplomaticRow['id']] = $diplomaticRow['ally_name'];
            } elseif ($own) {
                $diplomaticList[2][$diplomaticRow['level']][$diplomaticRow['id']] = $diplomaticRow['ally_name'];
            } else {
                $diplomaticList[1][$diplomaticRow['level']][$diplomaticRow['id']] = $diplomaticRow['ally_name'];
            }
        }

        $this->assign([
            'diploList' => $diplomaticList,
        ]);

        return $this->render('game/page.alliance.admin.diplomacy.default.html.twig');
    }

    protected function adminDiplomacyAccept()
    {
        $USER = & $this->state->USER;
        if (!$this->rights['DIPLOMATIC']) {
            $this->redirectToHome();
        }

        $db = $this->db;

        $id = $this->http()->_GP('id', 0);

        $targetAlliance = $this->getTargetAlliance($id);

        if (empty($targetAlliance)) {
            $this->redirectToHome();
        }

        // Only the recipient of an open request may accept it.
        $sql = "SELECT COUNT(*) as c FROM %%DIPLO%% WHERE id = :id AND owner_2 = :allianceId AND accept = 0;";
        $canAccept = $db->selectSingle($sql, [
            ':id' => $id,
            ':allianceId' => $this->allianceData['id'],
        ], 'c');
        if (!$canAccept) {
            $this->redirectTo('game?page=alliance&mode=admin&action=diplomacy');
        }

        if ($targetAlliance['diplo'] == DIPLO_PEACE) {
            $sql = "DELETE FROM %%DIPLO%% WHERE `level` IN (:war,:piece) 
                AND `owner_1` IN (:allianceId1,:allianceId2) AND `owner_2` IN (:allianceId1,:allianceId2)";
            $db->delete($sql, [
                ':war'          => DIPLO_WAR,
                ':piece'        => DIPLO_PEACE,
                ':allianceId1'  => $this->allianceData['id'],
                ':allianceId2'  => $targetAlliance['id'],
            ]);
        } else {
            $sql = "UPDATE %%DIPLO%% SET `accept` = 1, `request_time` = :request_time 
                WHERE `id` = :id AND `owner_2` = :allianceId";
            $db->update($sql, [
                ':allianceId'   => $this->allianceData['id'],
                ':id'           => $id,
                ':request_time' => $this->state->runtime->timestamp,
            ]);
        }

        $receivers = $this->getMessageReceivers($targetAlliance['id']);
        $receivers += $this->getMessageReceivers($this->allianceData['id']);

        $langReceivers = [];
        foreach ($receivers as $receiver) {
            if (!isset($langReceivers[$receiver['lang']])) {
                $langReceivers[$receiver['lang']] = [
                    'receivers' => [],
                    'lang' => $this->playerUtil()->getLanguage($receiver['lang']),
                ];
            }
            $langReceivers[$receiver['lang']]['receivers'][] = $receiver['id'];
        }
        foreach ($langReceivers as $lang => $receiver) {
            $this->playerUtil()->sendMessage(
                $receiver['receivers'],
                $USER['id'],
                $receiver['lang']['al_circular_alliance'] . $this->allianceData['ally_tag'],
                MESSAGE_TYPE_ALLIANCE,
                $targetAlliance['diplo'] == DIPLO_WAR ? $receiver['lang']['al_diplo_war_end'] : $receiver['lang']['al_diplo_accept_yes'],
                sprintf(
                    $targetAlliance['diplo'] == DIPLO_WAR ? $receiver['lang']['al_diplo_war_end_mes'] : $receiver['lang']['al_diplo_accept_yes_mes'],
                    $receiver['lang']['al_diplo_level'][(int) $targetAlliance['diplo']],
                    "[" . $this->allianceData['ally_tag'] . "] " . $this->allianceData['ally_name'],
                    "[" . $targetAlliance['ally_tag'] . "] " . $targetAlliance['ally_name'],
                ),
                $this->state->runtime->timestamp,
            );
        }

        $this->redirectTo('game?page=alliance&mode=admin&action=diplomacy');
    }

    protected function adminDiplomacyReject()
    {
        $USER = & $this->state->USER;
        if (!$this->rights['DIPLOMATIC']) {
            $this->redirectToHome();
        }

        $db = $this->db;

        $id = $this->http()->_GP('id', 0);

        $targetAlliance = $this->getTargetAlliance($id);

        if (empty($targetAlliance)) {
            $this->redirectToHome();
        }

        $receivers = $this->getMessageReceivers($targetAlliance['id'], true);

        $langReceivers = [];
        foreach ($receivers as $receiver) {
            if (!isset($langReceivers[$receiver['lang']])) {
                $langReceivers[$receiver['lang']] = [
                    'receivers' => [],
                    'lang' => $this->playerUtil()->getLanguage($receiver['lang']),
                ];
            }
            $langReceivers[$receiver['lang']]['receivers'][] = $receiver['id'];
        }
        foreach ($langReceivers as $lang => $receiver) {
            $this->playerUtil()->sendMessage(
                $receiver['receivers'],
                $USER['id'],
                $receiver['lang']['al_circular_alliance'] . $this->allianceData['ally_tag'],
                MESSAGE_TYPE_ALLIANCE,
                $receiver['lang']['al_diplo_accept_no'],
                sprintf(
                    $receiver['lang']['al_diplo_accept_no_mes'],
                    $receiver['lang']['al_diplo_level'][(int) $targetAlliance['diplo']],
                    "[" . $this->allianceData['ally_tag'] . "] " . $this->allianceData['ally_name'],
                    "[" . $targetAlliance['ally_tag'] . "] " . $targetAlliance['ally_name'],
                ),
                $this->state->runtime->timestamp,
            );
        }

        $sql = "DELETE FROM %%DIPLO%% WHERE `id` = :id AND (`owner_1` = :allianceId OR `owner_2` = :allianceId);";
        $db->delete($sql, [
            ':allianceId' => $this->allianceData['id'],
            ':id' => $id,
        ]);

        $this->redirectTo('game?page=alliance&mode=admin&action=diplomacy');
    }

    protected function adminDiplomacyDelete()
    {
        $USER = & $this->state->USER;
        if (!$this->rights['DIPLOMATIC']) {
            $this->redirectToHome();
        }

        $db = $this->db;

        $id = $this->http()->_GP('id', 0);

        $targetAlliance = $this->getTargetAlliance($id);

        if (empty($targetAlliance)) {
            $this->redirectToHome();
        }

        if ($targetAlliance['accept'] == 1) {
            $receivers = $this->getMessageReceivers($targetAlliance['id']);
            $receivers += $this->getMessageReceivers($this->allianceData['id']);

            $langReceivers = [];
            foreach ($receivers as $receiver) {
                if (!isset($langReceivers[$receiver['lang']])) {
                    $langReceivers[$receiver['lang']] = [
                        'receivers' => [],
                        'lang' => $this->playerUtil()->getLanguage($receiver['lang']),
                    ];
                }
                $langReceivers[$receiver['lang']]['receivers'][] = $receiver['id'];
            }
            foreach ($langReceivers as $lang => $receiver) {
                $this->playerUtil()->sendMessage(
                    $receiver['receivers'],
                    $USER['id'],
                    $receiver['lang']['al_circular_alliance'] . $this->allianceData['ally_tag'],
                    MESSAGE_TYPE_ALLIANCE,
                    $receiver['lang']['al_diplo_delete'],
                    sprintf(
                        $receiver['lang']['al_diplo_delete_mes'],
                        $receiver['lang']['al_diplo_level'][(int) $targetAlliance['diplo']],
                        "[" . $this->allianceData['ally_tag'] . "] " . $this->allianceData['ally_name'],
                        "[" . $targetAlliance['ally_tag'] . "] " . $targetAlliance['ally_name'],
                    ),
                    $this->state->runtime->timestamp,
                );
            }
        } else {
            $level = (int) $targetAlliance['diplo'];
            $receivers = $this->getMessageReceivers($targetAlliance['id'], true);

            $langReceivers = [];
            foreach ($receivers as $receiver) {
                if (!isset($langReceivers[$receiver['lang']])) {
                    $langReceivers[$receiver['lang']] = [
                        'receivers' => [],
                        'lang' => $this->playerUtil()->getLanguage($receiver['lang']),
                    ];
                }
                $langReceivers[$receiver['lang']]['receivers'][] = $receiver['id'];
            }
            foreach ($langReceivers as $lang => $receiver) {
                $this->playerUtil()->sendMessage(
                    $receiver['receivers'],
                    $USER['id'],
                    $receiver['lang']['al_circular_alliance'] . $this->allianceData['ally_tag'],
                    MESSAGE_TYPE_ALLIANCE,
                    $receiver['lang']['al_diplo_withdraw'],
                    sprintf(
                        $receiver['lang']['al_diplo_withdraw_mes'],
                        $receiver['lang']['al_diplo_level'][$level],
                        "[" . $this->allianceData['ally_tag'] . "] " . $this->allianceData['ally_name'],
                        "[" . $targetAlliance['ally_tag'] . "] " . $targetAlliance['ally_name'],
                    ),
                    $this->state->runtime->timestamp,
                );
            }
        }

        $sql = "DELETE FROM %%DIPLO%% WHERE `id` = :id AND (`owner_1` = :allianceId OR `owner_2` = :allianceId);";
        $db->delete($sql, [
            ':allianceId' => $this->allianceData['id'],
            ':id' => $id,
        ]);

        $this->redirectTo('game?page=alliance&mode=admin&action=diplomacy');
    }

    protected function adminDiplomacyCreate(): Response
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        if (!$this->rights['DIPLOMATIC']) {
            $this->redirectToHome();
        }

        $db = $this->db;

        $this->setWindow('popup');

        $diplomaticMode = $this->http()->_GP('diploMode', 0);
        $id = $this->http()->_GP('id', 0);

        $AllyList = [];
        $IdList = [];

        if ($diplomaticMode == DIPLO_PEACE) {
            $diplomaticAlly = $this->getTargetAlliance($id, $id == $USER['ally_id']);
            $IdList[] = $diplomaticAlly['id'];
            $AllyList[] = $diplomaticAlly['ally_name'];

            $diplo_level = [
                DIPLO_PEACE => $LNG['al_diplo_level'][DIPLO_PEACE],
            ];
        } else {
            $sql = "SELECT `ally_tag`, `ally_name`, `id` FROM %%ALLIANCE%% WHERE `id` != :allianceId AND `ally_universe` = :universe ORDER BY `ally_tag` ASC;";
            $diplomaticAlly = $db->select($sql, [
                ':allianceId' => $USER['ally_id'],
                ':universe' => $this->universe()->current(),
            ]);
            foreach ($diplomaticAlly as $i) {
                $IdList[] = $i['id'];
                $AllyList[] = $i['ally_name'];
            }

            $diplo_level = $LNG['al_diplo_level'];
            array_shift($diplo_level);
        }
        $this->assign([
            'diploLevel' => $diplo_level,
            'diploMode' => $diplomaticMode,
            'AllyList' => $AllyList,
            'IdList' => $IdList,
        ]);

        return $this->render('game/page.alliance.admin.diplomacy.create.html.twig');
    }

    protected function adminDiplomacyCreateProcessor()
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        if (!$this->rights['DIPLOMATIC']) {
            $this->redirectToHome();
        }

        $db = $this->db;

        $id = $this->http()->_GP('ally_id', '', UTF8_SUPPORT);
        $level = $this->http()->_GP('level', 0);
        $text = $this->http()->_GP('text', '', true);

        $targetAlliance = $this->getTargetAlliance($id, true);

        if (!in_array((int) $level, [DIPLO_WING, DIPLO_ALLIANCE_PACT, DIPLO_TRADE_PACT, DIPLO_NON_AGRESSION_PACT, DIPLO_WAR, DIPLO_SECRET_ALLIANCE_PACT, DIPLO_PEACE], true)) {
            $this->redirectToHome();
        }
        $level = (int) $level;

        if (empty($targetAlliance)) {
            $this->sendJSON([
                'error' => true,
                'message' => sprintf($LNG['al_diplo_no_alliance'], $id),
            ]);
        }

        if (!empty($targetAlliance['diplo']) && ($targetAlliance['diplo'] != DIPLO_WAR || $level != DIPLO_PEACE)) {
            $this->sendJSON([
                'error' => true,
                'message' => sprintf($LNG['al_diplo_exists'], $targetAlliance['ally_name']),
            ]);
        }
        if ($targetAlliance['id'] == $this->allianceData['id']) {
            $this->sendJSON([
                'error' => true,
                'message' => $LNG['al_diplo_same_alliance'],
            ]);
        }

        $this->setWindow('ajax');

        if ($level == DIPLO_WAR) {
            $receivers = $this->getMessageReceivers($targetAlliance['id']);
            $receivers += $this->getMessageReceivers($this->allianceData['id']);

            $langReceivers = [];
            foreach ($receivers as $receiver) {
                if (!isset($langReceivers[$receiver['lang']])) {
                    $langReceivers[$receiver['lang']] = [
                        'receivers' => [],
                        'lang' => $this->playerUtil()->getLanguage($receiver['lang']),
                    ];
                }
                $langReceivers[$receiver['lang']]['receivers'][] = $receiver['id'];
            }
            foreach ($langReceivers as $lang => $receiver) {
                $this->playerUtil()->sendMessage(
                    $receiver['receivers'],
                    $USER['id'],
                    $receiver['lang']['al_circular_alliance'] . $this->allianceData['ally_tag'],
                    MESSAGE_TYPE_ALLIANCE,
                    $receiver['lang']['al_diplo_war'],
                    sprintf(
                        $receiver['lang']['al_diplo_war_mes'],
                        "[" . $this->allianceData['ally_tag'] . "] " . $this->allianceData['ally_name'],
                        "[" . $targetAlliance['ally_tag'] . "] " . $targetAlliance['ally_name'],
                        $receiver['lang']['al_diplo_level'][$level],
                        $text,
                    ),
                    $this->state->runtime->timestamp,
                );
            }
        } else {
            $receivers = $this->getMessageReceivers($targetAlliance['id'], true);

            $langReceivers = [];
            foreach ($receivers as $receiver) {
                if (!isset($langReceivers[$receiver['lang']])) {
                    $langReceivers[$receiver['lang']] = [
                        'receivers' => [],
                        'lang' => $this->playerUtil()->getLanguage($receiver['lang']),
                    ];
                }
                $langReceivers[$receiver['lang']]['receivers'][] = $receiver['id'];
            }
            foreach ($langReceivers as $lang => $receiver) {
                $this->playerUtil()->sendMessage(
                    $receiver['receivers'],
                    $USER['id'],
                    $receiver['lang']['al_circular_alliance'] . $this->allianceData['ally_tag'],
                    MESSAGE_TYPE_ALLIANCE,
                    $receiver['lang']['al_diplo_ask'],
                    sprintf(
                        $receiver['lang']['al_diplo_ask_mes'],
                        $receiver['lang']['al_diplo_level'][$level],
                        "[" . $this->allianceData['ally_tag'] . "] " . $this->allianceData['ally_name'],
                        "[" . $targetAlliance['ally_tag'] . "] " . $targetAlliance['ally_name'],
                        $text,
                    ),
                    $this->state->runtime->timestamp,
                );
            }
        }

        $sql = "INSERT INTO %%DIPLO%% SET owner_1 = :allianceId, owner_2 = :allianceTargetID, level	= :level,"
            . " accept = :accept, accept_text = :text, universe = :universe, request_time = :request_time";
        $db->insert($sql, [
            ':allianceId' => $USER['ally_id'],
            ':allianceTargetID' => $targetAlliance['id'],
            ':level' => $level,
            ':accept' => $level == DIPLO_WAR ? 1 : 0,
            ':text' => $text,
            ':universe' => $this->universe()->current(),
            ':request_time' => $this->state->runtime->timestamp,
        ]);

        $this->sendJSON([
            'error' => false,
            'message' => $LNG['al_diplo_create_done'],
        ]);
    }

    private function getTargetAlliance(int $id, bool $ally_id = false)
    {
        $USER = & $this->state->USER;
        $db = $this->db;

        if ($ally_id) {
            $sql = "SELECT a.id, a.ally_name, a.ally_owner, a.ally_tag, d.level as diplo, d.accept
                FROM %%ALLIANCE%% a
                LEFT JOIN %%DIPLO%% d ON (d.owner_1 = :id AND d.owner_2 = :allianceId) OR (d.owner_2 = :id AND d.owner_1 = :allianceId)
                WHERE a.ally_universe = :universe AND a.id = :id;";

            $targetAlliance = $db->selectSingle($sql, [
                ':allianceId' => $USER['ally_id'],
                ':id' => $id,
                ':universe' => $this->universe()->current(),
            ]);
        } else {
            $sql = "SELECT a.id, a.ally_name, a.ally_owner, a.ally_tag, d.level as diplo, d.accept
                FROM %%DIPLO%% d
                JOIN %%ALLIANCE%% a ON a.id = d.owner_1 OR a.id = d.owner_2
                WHERE a.ally_universe = :universe AND d.id = :id AND a.id != :allianceId
                AND (d.owner_1 = :allianceId OR d.owner_2 = :allianceId);";

            $targetAlliance = $db->selectSingle($sql, [
                ':allianceId' => $USER['ally_id'],
                ':id' => $id,
                ':universe' => $this->universe()->current(),
            ]);
        }

        return $targetAlliance;
    }

    private function getMessageReceivers(int $targetAllyID, bool $diplomats = false, bool $managers = false)
    {
        $USER = & $this->state->USER;
        $db = $this->db;

        $sql = "SELECT u.id, us.lang FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON u.id = us.user_id";
        if ($diplomats || $managers) {
            $sql = $sql . " JOIN %%ALLIANCE%% a ON u.ally_id = a.id";
            $sql = $sql . " LEFT JOIN %%ALLIANCE_RANK%% r ON u.ally_id = r.allianceID AND u.ally_rank_id = r.rankID";
        }
        if (!$managers) {
            $sql = $sql . " WHERE (u.ally_id = :allianceId OR u.ally_id = :id)";
            if ($diplomats) {
                $sql = $sql . " AND (r.DIPLOMATIC = 1 OR u.id = a.ally_owner)";
            }
            $sql = $sql . ";";

            $receivers = $db->select($sql, [
                ':allianceId' => $USER['ally_id'],
                ':id' => $targetAllyID,
            ]);
        } else {
            $sql = $sql . " WHERE u.ally_id = :allianceId AND (r.MANAGEAPPLY = 1 OR u.id = a.ally_owner);";

            $receivers = $db->select($sql, [
                ':allianceId' => $targetAllyID,
            ]);
        }

        return $receivers;
    }
}

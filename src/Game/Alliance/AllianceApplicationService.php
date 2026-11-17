<?php

namespace App\Game\Alliance;

use App\Game\Enum\MessageType;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Applications to an alliance: searching alliances, applying, withdrawing, and the managers' side (list, details,
 * accept/decline with the answer message).
 *
 * Rules: an application needs an open alliance (ally_request_notallow = 0) with a free slot and the minimum points;
 * accepting re-checks inside a transaction (applicant may have joined elsewhere meanwhile, alliance may have filled
 * up) and then adds the applicant as rank-less member.
 */
class AllianceApplicationService
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly AllianceRepository $repository,
        private readonly AllianceMessenger $messenger,
    ) {}

    /**
     * Alliances of the current universe whose name contains the text (best matches first, max. 25). Without a text the
     * biggest alliances are listed, so the page is browsable.
     *
     * @return list<array{id: mixed, tag: mixed, members: mixed, name: mixed, maxMembers: mixed, open: bool}>
     */
    public function search(string $searchText): array
    {
        $searchList = [];

        if (empty($searchText)) {
            $sql = "SELECT id, ally_name, ally_tag, ally_members, ally_max_members, ally_request_notallow
				FROM %%ALLIANCE%%
				WHERE ally_universe = :universe
				ORDER BY ally_members DESC, ally_name ASC LIMIT 25;";
            $searchResult = $this->db->select($sql, [':universe' => $this->universe()->current()]);
        } else {
            $sql = "SELECT id, ally_name, ally_tag, ally_members, ally_max_members, ally_request_notallow
				FROM %%ALLIANCE%%
				WHERE ally_universe = :universe AND ally_name LIKE :searchTextLike
				ORDER BY (
				  IF(ally_name = :searchTextEqual, 1, 0) + IF(ally_name LIKE :searchTextLike, 1, 0)
				) DESC,ally_name ASC LIMIT 25;";

            $searchResult = $this->db->select($sql, [
                ':universe' => $this->universe()->current(),
                ':searchTextLike' => '%' . $searchText . '%',
                ':searchTextEqual' => $searchText,
            ]);
        }

        foreach ($searchResult as $searchRow) {
            $searchList[] = [
                'id' => $searchRow['id'],
                'tag' => $searchRow['ally_tag'],
                'members' => $searchRow['ally_members'],
                'name' => $searchRow['ally_name'],
                'maxMembers' => $searchRow['ally_max_members'],
                'open' => (int) $searchRow['ally_request_notallow'] === 0 && (int) $searchRow['ally_members'] < (int) $searchRow['ally_max_members'],
            ];
        }

        return $searchList;
    }

    /**
     * the alliance the acting user applied at (id, ally_name, ally_tag)
     *
     * @return array<mixed>
     */
    public function pendingTarget(): array|false|null
    {
        $sql = "SELECT a.id, a.ally_name, a.ally_tag
            FROM %%ALLIANCE_REQUEST%% r
            INNER JOIN %%ALLIANCE%% a ON a.id = r.allianceId
            WHERE r.userId = :userId;";

        return $this->db->selectSingle($sql, [
            ':userId' => $this->state->USER['id'],
        ]);
    }

    /**
     * Loads the alliance for the application form and checks that the acting user may apply.
     *
     * @return array<string, mixed> ally_tag, ally_request (application text template), ...
     * @throws AllianceException home when the alliance does not exist in this universe; message pages when it is
     *         closed, full or the user lacks the minimum points
     */
    public function applicationTarget(int|string $allianceId): array
    {
        $LNG = $this->state->LNG;

        $sql = "SELECT ally_tag, ally_request, ally_request_notallow, ally_owner, ally_members, ally_max_members, ally_request_min_points
            FROM %%ALLIANCE%% WHERE id = :allianceId AND ally_universe = :universe;";
        $allianceResult = $this->db->selectSingle($sql, [
            ':allianceId' => $allianceId,
            ':universe' => $this->universe()->current(),
        ]);

        if (!$allianceResult) {
            throw AllianceException::home();
        }

        if ($allianceResult['ally_request_notallow'] == 1) {
            throw AllianceException::message($LNG['al_alliance_closed'], $LNG['sys_forward'], 'game_alliance_show');
        }

        if ($allianceResult['ally_members'] >= $allianceResult['ally_max_members']) {
            throw AllianceException::message($LNG['al_alliance_full'], $LNG['sys_forward'], 'game_alliance_show');
        }

        if ($allianceResult['ally_request_min_points'] > 0) {
            $userPoints = $this->repository->userPoints($this->state->USER['id']);
            if ($userPoints < $allianceResult['ally_request_min_points']) {
                throw AllianceException::message(
                    $LNG->format('al_alliance_min_points', $allianceResult['ally_request_min_points']),
                    $LNG['sys_forward'],
                    'game_alliance_show',
                );
            }
        }

        return $allianceResult;
    }

    /**
     * Stores the application of the acting user and tells the alliance's managers.
     *
     * Side effects: row in alliance_requests, one message per manager.
     */
    public function submit(int|string $allianceId, string $text): void
    {
        $sql = "INSERT INTO %%ALLIANCE_REQUEST%% SET
            allianceId	= :allianceId,
            text		= :text,
            time		= :time,
            userId		= :userId;";

        $this->db->insert($sql, [
            ':allianceId' => $allianceId,
            ':text' => $text,
            ':time' => $this->state->runtime->timestamp,
            ':userId' => $this->state->USER['id'],
        ]);

        $this->messenger->applicationReceived($allianceId);
    }

    /**
     * Withdraws the acting user's application.
     *
     * @return mixed tag of the alliance he applied at
     */
    public function cancel(): mixed
    {
        $db = $this->db;
        $sql = "SELECT a.ally_tag FROM %%ALLIANCE_REQUEST%% r INNER JOIN %%ALLIANCE%% a ON a.id = r.allianceId"
            . " WHERE r.userId = :userId;";
        $allyTag = $db->selectSingle($sql, [
            ':userId' => $this->state->USER['id'],
        ], 'ally_tag');

        $sql = "DELETE FROM %%ALLIANCE_REQUEST%% WHERE userId = :userId;";
        $db->delete($sql, [
            ':userId' => $this->state->USER['id'],
        ]);

        return $allyTag;
    }

    /**
     * Open applications of the alliance for the manager list.
     *
     * @param array<string, mixed> $alliance
     * @return list<array{username: mixed, id: mixed, time: string}>
     */
    public function listFor(array $alliance): array
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

        $sql = "SELECT applyID, u.username, r.time FROM %%ALLIANCE_REQUEST%% r INNER JOIN %%USERS%% u"
            . " ON r.userId = u.id WHERE r.allianceId = :allianceId;";
        $applyResult = $this->db->select($sql, [
            ':allianceId' => $alliance['id'],
        ]);

        $applyList = [];
        foreach ($applyResult as $applyRow) {
            $applyList[] = [
                'username' => $applyRow['username'],
                'id' => $applyRow['applyID'],
                'time' => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $applyRow['time'], $USER['timezone']),
            ];
        }

        return $applyList;
    }

    /**
     * One application of this alliance with the applicant's id and activity (null when it does not exist).
     *
     * @param array<string, mixed> $alliance
     * @return array<string, mixed>|null applyID, time, text, id, register_time, onlinetime
     */
    public function find(array $alliance, int|string $applyId): ?array
    {
        $sql = 'SELECT r.`applyID`, r.`time`, r.`text`, u.`id`, u.`register_time`, u.`onlinetime`
            FROM %%ALLIANCE_REQUEST%% AS r
            LEFT JOIN %%USERS%% AS u ON r.userId = u.id
            WHERE applyID = :applyID AND r.allianceId = :allianceId;';

        return $this->db->selectSingle($sql, [
            ':applyID' => $applyId,
            ':allianceId' => $alliance['id'],
        ]) ?: null;
    }

    /**
     * Accepts or declines an application and tells the applicant (in his language).
     *
     * Accepting: inside a transaction the applicant row and the member count are locked; if the applicant joined
     * another alliance meanwhile the request is just dropped (redirect), if the alliance is full meanwhile the
     * application is declined with the "alliance full" text, otherwise the applicant becomes a rank-less member
     * (users, statpoints, member counter).
     *
     * @param array<string, mixed> $alliance
     * @param string $answer 'yes' accepts, anything else declines
     * @param string $text   the manager's free text (already converted to <br>)
     * @return string|null 'yes' or 'no' (what happened, for the manager's confirmation), null when the application
     *                     no longer exists
     * @throws AllianceException redirect to the application list when the applicant is already in an alliance
     */
    public function answer(array $alliance, int|string $applyID, string $answer, string $text): ?string
    {
        $USER = $this->state->USER;
        $db = $this->db;

        $sql = "SELECT userId FROM %%ALLIANCE_REQUEST%% WHERE applyID = :applyID AND allianceId = :allianceId;";
        $userId = $db->selectSingle($sql, [
            ':applyID' => $applyID,
            ':allianceId' => $alliance['id'],
        ], 'userId');

        // only if alliance request still exist
        if (!$userId) {
            return null;
        }

        $LNG = $this->playerLookup()->getLanguage(null, $userId);
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
                throw AllianceException::redirect('game_alliance_admin_manage_apply');
            }
            $sql = "SELECT COUNT(*) as member_count FROM %%USERS%% WHERE ally_id = :allianceId FOR UPDATE";
            $memberCount = $db->selectSingle($sql, [':allianceId' => $alliance['id']], 'member_count');
            if ($memberCount >= $alliance['ally_max_members']) {
                $db->rollback();
                $answer = 'no';
                $text = $LNG['al_alliance_full'];
                $subject = $LNG['al_you_was_declined'] . $alliance['ally_name'];

                $this->deleteRequest($alliance, $applyID);
            } else {
                $this->deleteRequest($alliance, $applyID);

                $sql = "UPDATE %%USERS%% SET ally_id = :allianceId, ally_register_time = :time, ally_rank_id = 0
                    WHERE id = :userId;";
                $db->update($sql, [
                    ':allianceId' => $alliance['id'],
                    ':time' => $this->state->runtime->timestamp,
                    ':userId' => $userId,
                ]);

                $sql = "UPDATE %%STATPOINTS%% SET id_ally = :allianceId WHERE id_owner = :userId AND stat_type = 1;";
                $db->update($sql, [
                    ':allianceId' => $alliance['id'],
                    ':userId' => $userId,
                ]);

                $sql = "UPDATE %%ALLIANCE%% SET ally_members = ally_members + 1 WHERE id = :allianceId;";
                $db->update($sql, [
                    ':allianceId' => $alliance['id'],
                ]);

                $db->commit();
                $text = $LNG['al_hi_the_alliance'] . $alliance['ally_name'] . $LNG['al_has_accepted'] . $text;
                $subject = $LNG['al_you_was_acceted'] . $alliance['ally_name'];
            }
        } else {
            $this->deleteRequest($alliance, $applyID);

            $text = $LNG['al_hi_the_alliance'] . $alliance['ally_name'] . $LNG['al_has_declined'] . $text;
            $subject = $LNG['al_you_was_declined'] . $alliance['ally_name'];
        }

        $senderName = $LNG['al_the_alliance'] . $alliance['ally_name'] . ' ['
            . $alliance['ally_tag'] . ']';
        $this->playerMessenger()->sendMessage($userId, $USER['id'], $senderName, MessageType::Alliance->value, $subject, $text, $this->state->runtime->timestamp);

        return $answer == 'yes' ? 'yes' : 'no';
    }

    /**
     * Template variables of the application detail page: applicant data and his statistics, honouring the spy tech
     * rules for what the manager may see.
     *
     * @param array<string, mixed> $applyDetail row of find(); its `text` is converted to safe HTML here
     * @param object $playerCard the (initialised) PlayerCardController providing the statistics getters
     * @return array<string, mixed>
     */
    public function detailVariables(array $applyDetail, object $playerCard): array
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $view = $this->viewHelpers();

        $targetUserId = $applyDetail['id'];
        $stats = $playerCard->get_stats($targetUserId);
        $showPlayer = true;
        $showTotal = true;
        $viewer = $this->models()->user($USER);
        $showBuild = $this->protectionRules()->spyTechAllowedFor(2, $targetUserId, $viewer);
        $showTech = $this->protectionRules()->spyTechAllowedFor(4, $targetUserId, $viewer);
        $showDef = $this->protectionRules()->spyTechAllowedFor(6, $targetUserId, $viewer);
        $showFleet = $this->protectionRules()->spyTechAllowedFor(6, $targetUserId, $viewer);
        $showBattle = $this->protectionRules()->spyTechAllowedFor(6, $targetUserId, $viewer);

        $player_data = $playerCard->get_player_data($stats, $showPlayer);
        $total_data = $playerCard->get_total_data($stats, $showTotal);
        $build_data = $playerCard->get_build_data($stats, $showBuild);
        $tech_data = $playerCard->get_tech_data($stats, $showTech);
        $def_data = $playerCard->get_def_data($stats, $showDef);
        $fleet_data = $playerCard->get_fleet_data($stats, $showFleet);
        $battle_stats = $playerCard->get_battle_stats($stats, $showBattle);
        $units = $playerCard->get_units($stats, $showBattle);
        $real_units = $playerCard->get_real_units($stats, $showBattle);

        $applyDetail['text'] = AllianceText::multiline($applyDetail['text']);

        return [
            'applyDetail'   => $applyDetail,
            'name'          => $player_data['username'],
            'homeplanet'    => $player_data['planetname'],
            'galaxy'        => $player_data['galaxy'],
            'system'        => $player_data['system'],
            'planet'        => $player_data['planet'],
            'apply_time'    => $view->formatDate($LNG['php_tdformat'], $applyDetail['time'], $USER['timezone']),
            'register_time' => $view->formatDate($LNG['php_tdformat'], $applyDetail['register_time'], $USER['timezone']),
            'onlinetime'    => $view->formatDate($LNG['php_tdformat'], $applyDetail['onlinetime'], $USER['timezone']),
            'playerdestory' => $LNG->format('pl_destroy', $player_data['username']),
            'realdestory'   => $LNG->format('pl_destroy_real', $player_data['username']),
            'tech_rank'     => $view->prettyNumber($tech_data['tech_rank']),
            'tech_points'   => $view->prettyNumber($tech_data['tech_points']),
            'build_rank'    => $view->prettyNumber($build_data['build_rank']),
            'build_points'  => $view->prettyNumber($build_data['build_points']),
            'defs_rank'     => $view->prettyNumber($def_data['defs_rank']),
            'defs_points'   => $view->prettyNumber($def_data['defs_points']),
            'fleet_rank'    => $view->prettyNumber($fleet_data['fleet_rank']),
            'fleet_points'  => $view->prettyNumber($fleet_data['fleet_points']),
            'total_rank'    => $view->prettyNumber($total_data['total_rank']),
            'total_points'  => $view->prettyNumber($total_data['total_points']),
            'wons'          => $view->prettyNumber($battle_stats['wons']),
            'loos'          => $view->prettyNumber($battle_stats['loos']),
            'draws'         => $view->prettyNumber($battle_stats['draws']),
            'kbmetal'       => $view->prettyNumber($units['kbmetal']),
            'kbcrystal'     => $view->prettyNumber($units['kbcrystal']),
            'lostunits'     => $view->prettyNumber($units['lostunits']),
            'desunits'      => $view->prettyNumber($units['desunits']),
            'realdesunits'  => $view->prettyNumber($real_units["destroyed"]),
            'reallostunits' => $view->prettyNumber($real_units["lost"]),
            'realmetal'     => $view->prettyNumber($real_units["debris_metal"]),
            'realcrystal'   => $view->prettyNumber($real_units["debris_crystal"]),
            'totalfights'   => $view->prettyNumber($battle_stats['totalfights']),
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
        ];
    }

    /**
     * @param array<string, mixed> $alliance
     */
    private function deleteRequest(array $alliance, int|string $applyID): void
    {
        $sql = "DELETE FROM %%ALLIANCE_REQUEST%% WHERE applyID = :applyID AND allianceId = :allianceId";
        $this->db->delete($sql, [
            ':applyID' => $applyID,
            ':allianceId' => $alliance['id'],
        ]);
    }
}

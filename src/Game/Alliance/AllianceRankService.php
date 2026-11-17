<?php

namespace App\Game\Alliance;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Ranks of an alliance and the rights attached to them: the permission page (list/create/rename/delete/change
 * rights) and the assignment of ranks to members.
 *
 * Rights rules (security fixes GB-10/12/30/31/34 stay in force): nobody can grant, change or assign a right he does
 * not hold himself (the columns of rights the actor lacks are simply left out of the INSERT/UPDATE, and ranks that
 * carry such a right cannot be assigned), nobody can change his own rank's rights, the leader cannot be given a rank,
 * and every statement is scoped to the acting alliance.
 *
 * The right column names that are concatenated into the INSERT/UPDATE come from AllianceRights::AVAILABLE only
 * (whitelist), all values are bound.
 */
class AllianceRankService
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly AllianceRepository $repository,
    ) {}

    /**
     * Data of the permission page.
     *
     * @param array<string, mixed> $alliance
     * @param array<string, bool|int|string> $rights rights of the acting user
     * @return array{rankList: array, changeableRanks: array<int|string, bool>, availableRanks: array<int, string>}
     *         rankList: rankID => full rank row; changeableRanks: rank may be edited (not the own rank, no right
     *         the actor lacks); availableRanks: index => name of the rights the actor holds (editable columns)
     */
    public function permissionPage(array $alliance, array $rights): array
    {
        $USER = $this->state->USER;

        $sql = "SELECT * FROM %%ALLIANCE_RANK%% WHERE allianceId = :allianceId;";
        $rankResult = $this->db->select($sql, [
            ':allianceId' => $alliance['id'],
        ]);

        $rankList = [];
        $changeableRanks = [];
        foreach ($rankResult as $rankRow) {
            $rankList[$rankRow['rankID']] = $rankRow;
            $changeable = AllianceRights::canHandOut($rankRow, $rights);
            $changeableRanks[$rankRow['rankID']] = $USER['ally_rank_id'] != $rankRow['rankID'] && $changeable;
        }

        $availableRanks = [];
        foreach (AllianceRights::AVAILABLE as $rankId => $rankName) {
            if ($rights[$rankName]) {
                $availableRanks[$rankId] = $rankName;
            }
        }

        return [
            'rankList' => $rankList,
            'changeableRanks' => $changeableRanks,
            'availableRanks' => $availableRanks,
        ];
    }

    /**
     * Creates a rank. Only the rights the actor holds can be set; form keys are right indexes.
     *
     * @param array<string, mixed> $alliance
     * @param array<string, bool|int|string> $rights rights of the acting user
     * @param array<string, mixed> $newRank `rankName` plus right index => value
     * @throws AllianceException message page when the name is invalid or longer than 32 characters
     */
    public function create(array $alliance, array $rights, array $newRank): void
    {
        $LNG = $this->state->LNG;

        if (!$this->playerRegistration()->isNameValid($newRank['rankName']) || mb_strlen($newRank['rankName']) > 32) {
            throw AllianceException::message($LNG['al_invalid_rank_name'], $LNG['sys_back'], 'game_alliance_admin_permissions');
        }

        $insert = $this->db->qb()
            ->insert($this->db->table('ALLIANCE_RANK'))
            ->setValue('rankName', ':rankName')
            ->setValue('allianceID', ':allianceID')
            ->setParameter('rankName', $newRank['rankName'])
            ->setParameter('allianceID', $alliance['id']);

        unset($newRank['rankName']);

        // only the rights the acting user holds themselves can be handed out (columns from AllianceRights::AVAILABLE)
        foreach ($newRank as $key => $value) {
            if (isset(AllianceRights::AVAILABLE[$key]) && $rights[AllianceRights::AVAILABLE[$key]]) {
                $right = AllianceRights::AVAILABLE[$key];
                $insert->setValue('`' . $right . '`', ':' . $right)->setParameter($right, $value == 1 ? 1 : 0);
            }
        }

        $this->db->execute($insert);
    }

    /**
     * Deletes a rank of the alliance; members holding it fall back to "no rank". Ranks out of the actor's reach
     * (own rank, rights the actor lacks) are left alone.
     *
     * @param array<string, mixed> $alliance
     * @param array<string, bool|int|string> $rights rights of the acting user
     */
    public function delete(array $alliance, array $rights, int|string $rankId): void
    {
        if (!$this->canManageRank($alliance, $rights, $rankId)) {
            return;
        }

        $sql = "DELETE FROM %%ALLIANCE_RANK%% WHERE rankID = :rankID AND allianceId = :allianceId;";
        $this->db->delete($sql, [
            ':allianceId' => $alliance['id'],
            ':rankID' => $rankId,
        ]);

        $sql = "UPDATE %%USERS%% SET ally_rank_id = 0 WHERE ally_rank_id = :rankID AND ally_id = :allianceId;";
        $this->db->update($sql, [
            ':allianceId' => $alliance['id'],
            ':rankID' => $rankId,
        ]);
    }

    /**
     * Renames the ranks and rewrites their rights from the permission form. For every submitted rank the rights the
     * actor holds are set from the checkboxes (a missing checkbox clears the right), the other rights stay untouched.
     *
     * @param array<string, mixed> $alliance
     * @param array<string, bool|int|string> $rights rights of the acting user
     * @param array<int|string, array<int|string, mixed>> $rankData rankID => [rankName, right index => on]
     * @throws AllianceException message page when a name is invalid or longer than 32 characters
     */
    public function update(array $alliance, array $rights, array $rankData): void
    {
        $LNG = $this->state->LNG;

        foreach ($rankData as $rankId => $rowData) {
            if (!$this->canManageRank($alliance, $rights, $rankId)) {
                continue;
            }

            if (
                !$this->playerRegistration()->isNameValid($rowData['rankName'] ?? '')
                || mb_strlen($rowData['rankName']) > 32
            ) {
                throw AllianceException::message($LNG['al_invalid_rank_name'], $LNG['sys_back'], 'game_alliance_admin_permissions');
            }

            $values = ['rankName' => $rowData['rankName']];

            unset($rowData['rankName']);

            // only the rights the acting user holds themselves are rewritten (columns from AllianceRights::AVAILABLE)
            foreach (AllianceRights::AVAILABLE as $key => $right) {
                if ($rights[$right]) {
                    $values[$right] = isset($rowData[$key]) ? 1 : 0;
                }
            }

            $this->db->updateRows('ALLIANCE_RANK', $values, ['rankID' => $rankId, 'allianceID' => $alliance['id']]);
        }
    }

    /**
     * Rank choices for the member administration page.
     *
     * @param array<string, mixed> $alliance
     * @param array<string, bool|int|string> $rights rights of the acting user
     * @return array{rankList: array<int|string, string>, rankSelectList: array<int|string, string>} all ranks by
     *         name (0 = "new member") and the ranks the actor may assign
     */
    public function memberChoices(array $alliance, array $rights): array
    {
        $LNG = $this->state->LNG;

        $rankList = [$LNG['al_new_member_rank_text']];
        $rankSelectList = $rankList;

        foreach ($this->repository->ranksWithRights($alliance['id']) as $rankRow) {
            if (AllianceRights::canHandOut($rankRow, $rights)) {
                $rankSelectList[$rankRow['rankID']] = $rankRow['rankName'];
            }

            $rankList[$rankRow['rankID']] = $rankRow['rankName'];
        }

        return ['rankList' => $rankList, 'rankSelectList' => $rankSelectList];
    }

    /**
     * Assigns ranks to members. Skips the leader, ranks outside the alliance and ranks that carry a right the actor
     * lacks (rank 0 = "no rank" is always allowed); only members of the alliance are touched.
     *
     * @param array<string, mixed> $alliance
     * @param array<string, bool|int|string> $rights rights of the acting user
     * @param array<int|string, int|string> $userRanks user id => rank id
     */
    public function assign(array $alliance, array $rights, array $userRanks): void
    {
        $assignable = [0 => true];
        foreach ($this->repository->ranksWithRights($alliance['id'], false) as $rankRow) {
            if (AllianceRights::canHandOut($rankRow, $rights)) {
                $assignable[$rankRow['rankID']] = true;
            }
        }

        // current ranks of the members: a member whose rank carries a right the actor lacks cannot be changed
        $currentRanks = [];
        foreach ($this->db->select('SELECT id, ally_rank_id FROM %%USERS%% WHERE ally_id = :allianceId;', [':allianceId' => $alliance['id']]) as $member) {
            $currentRanks[$member['id']] = $member['ally_rank_id'];
        }

        foreach ($userRanks as $userId => $rankId) {
            if (
                $userId == $alliance['ally_owner'] || !isset($assignable[$rankId])
                || !isset($currentRanks[$userId]) || !isset($assignable[$currentRanks[$userId]])
            ) {
                continue;
            }

            $sql = 'UPDATE %%USERS%% SET ally_rank_id = :rankID WHERE id = :userId AND ally_id = :allianceId;';
            $this->db->update($sql, [
                ':allianceId' => $alliance['id'],
                ':rankID' => (int) $rankId,
                ':userId' => (int) $userId,
            ]);
        }
    }

    /**
     * Whether the rank belongs to the alliance, is not the actor's own rank and carries no right the actor lacks
     * (rank hierarchy, same rule as permissionPage()'s changeableRanks).
     *
     * @param array<string, mixed> $alliance
     * @param array<string, bool|int|string> $rights rights of the acting user
     */
    private function canManageRank(array $alliance, array $rights, int|string $rankId): bool
    {
        if ($this->state->USER['ally_rank_id'] == $rankId) {
            return false;
        }
        foreach ($this->repository->ranksWithRights($alliance['id'], false) as $rankRow) {
            if ($rankRow['rankID'] == $rankId) {
                return AllianceRights::canHandOut($rankRow, $rights);
            }
        }

        return false;
    }
}

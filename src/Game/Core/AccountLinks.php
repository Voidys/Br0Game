<?php

namespace App\Game\Core;

/**
 * Collusion check between two accounts: same last IP, same multi group (MULTI_TO_USERS) and, optionally, the social
 * links same alliance and buddies. Used by the rebuild protection and the bash war exemption.
 *
 * Collaborators: Database (USERS, MULTI_TO_USERS, BUDDY).
 */
class AccountLinks
{
    public function __construct(
        protected \App\Game\Core\Database $db,
    ) {}

    /**
     * Whether two different accounts are linked. $includeSocial adds the shared alliance (ally_id != 0) and buddy links.
     */
    public function linked(int $a, int $b, bool $includeSocial): bool
    {
        if ($a === $b) {
            return false;
        }

        $users = [];
        foreach ($this->db->select('SELECT `id`, `user_lastip`, `ally_id` FROM %%USERS%% WHERE `id` IN (:a, :b);', [':a' => $a, ':b' => $b]) as $row) {
            $users[(int) $row['id']] = $row;
        }
        if (!isset($users[$a], $users[$b])) {
            return false;
        }

        $ip = (string) $users[$a]['user_lastip'];
        if ($ip !== '' && $ip === (string) $users[$b]['user_lastip']) {
            return true;
        }

        $multi = $this->db->selectSingle(
            'SELECT count(*) as anz FROM %%MULTI_TO_USERS%% x JOIN %%MULTI_TO_USERS%% y ON y.`multiID` = x.`multiID`
            WHERE x.`userID` = :a AND y.`userID` = :b;',
            [':a' => $a, ':b' => $b],
            'anz',
        );
        if ($multi > 0) {
            return true;
        }

        if (!$includeSocial) {
            return false;
        }

        if ($users[$a]['ally_id'] != 0 && $users[$a]['ally_id'] == $users[$b]['ally_id']) {
            return true;
        }

        $buddy = $this->db->selectSingle(
            'SELECT count(*) as anz FROM %%BUDDY%%
            WHERE (`sender` = :a AND `owner` = :b) OR (`sender` = :b AND `owner` = :a);',
            [':a' => $a, ':b' => $b],
            'anz',
        );

        return $buddy > 0;
    }
}

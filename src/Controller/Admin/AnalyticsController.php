<?php

namespace App\Controller\Admin;

use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;
use App\Twig\GameEscaping;
use Twig\Markup;

/** Player statistics: user counts and the list of online players. */
#[\App\Routing\GamePage('admin.php', 'analytics')]
#[\App\Routing\GamePage('admin.php', 'analytics', uniPrefix: true)]
class AnalyticsController extends AbstractAdminController
{
    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $db = $this->db;

        if (!isset($this->state->requestQuery['order'])) {
            $this->state->requestQuery['order'] = '';
        }
        $ORDER = $this->state->requestQuery['order'] == 'id' ? "id" : "username";
        if (!isset($this->state->requestQuery['order2'])) {
            $this->state->requestQuery['order2'] = '';
        }
        $ORDER2 = $this->state->requestQuery['order2'] == 'id' ? "id" : "username";

        if (isset($this->state->requestPost['user_name']) && isset($this->state->requestPost['userListSubmit'])) {
            $sql = 'UPDATE %%USERS%% SET `enable_analytics` = 1 WHERE `id` = :userID;';
            $db->update($sql, [
                ':userID' => $this->state->requestPost['user_name'],
            ]);
        } else {
            if (isset($this->state->requestPost['analyse_name']) && isset($this->state->requestPost['analysedUsersSubmit'])) {
                $sql = 'UPDATE %%USERS%% SET `enable_analytics` = 0 WHERE `id` = :userID;';
                $db->update($sql, [
                    ':userID' => $this->state->requestPost['analyse_name'],
                ]);
                $sql = 'DELETE FROM %%USERS_ANALYTICS%% WHERE `userID` = :userID;';
                $db->delete($sql, [
                    ':userID' => $this->state->requestPost['analyse_name'],
                ]);
            }
        }

        $userSelect = ['List' => '', 'ListBan' => ''];
        $usercount = 0;
        $bancount = 0;

        $sql = 'SELECT `username`, `id`, `enable_analytics` FROM %%USERS%%
        WHERE `authlevel` = 0 AND `universe` = :uni
        ORDER BY ' . $ORDER . ' ASC;';
        $userList = $db->select($sql, [
            ':uni' => $this->universe()->getEmulated(),
        ]);
        foreach ($userList as $a) {
            $userSelect['List'] .= '<option value="' . $a['id'] . '">' . GameEscaping::escape((string) $a['username'], 'UTF-8') . '&nbsp;&nbsp;(ID:&nbsp;' . $a['id'] . ')' . (($a['enable_analytics'] == '1') ? '&nbsp;(tracked)' : '') . '</option>';
            $usercount++;
        }

        $sql = 'SELECT `username`, `id`, `enable_analytics` FROM %%USERS%%
        WHERE `enable_analytics` = 1 AND `universe` = :uni
        ORDER BY ' . $ORDER2 . ' ASC;';
        $userListBan = $db->select($sql, [
            ':uni' => $this->universe()->getEmulated(),
        ]);
        foreach ($userListBan as $b) {
            $userSelect['ListBan'] .= '<option value="' . $b['id'] . '">' . GameEscaping::escape((string) $b['username'], 'UTF-8') . '&nbsp;&nbsp;(ID:&nbsp;' . $b['id'] . ')</option>';
            $bancount++;
        }

        $sql = 'SELECT ac.`userID`, u.`username`, a.`ally_name`, ac.`onlinetime`, ac.`url`
        FROM %%USERS_ANALYTICS%% AS ac
        LEFT JOIN %%USERS%% AS u ON u.`id` = ac.`userID`
        LEFT JOIN %%ALLIANCE%% AS a ON a.`id` = u.`ally_id`
        WHERE u.`universe` = :uni ORDER BY `onlinetime` DESC;';

        $analytics = $db->select($sql, [
            ':uni' => $this->universe()->getEmulated(),
        ]);

        foreach ($analytics as &$Data) {
            $Data['onlinetime'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $Data['onlinetime']);
        }

        $this->assign([
            'UserSelect'      => array_map(static fn(string $options) => new Markup($options, 'UTF-8'), $userSelect),
            'usercount'       => $usercount,
            'bancount'        => $bancount,
            'analytics'       => $analytics,
            'bo_select_title' => $LNG['bo_select_title'],
        ]);
        return $this->render('admin/Analytics.html.twig');
    }
}

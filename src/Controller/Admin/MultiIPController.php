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

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Log;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Multi account list (accounts sharing an IP address); entries can be marked as known or unknown.
 */
#[\App\Routing\GamePage('admin.php', 'multiIP')]
#[\App\Routing\GamePage('admin.php', 'multiIP', uniPrefix: true)]
class MultiIPController extends AbstractAdminController
{
    /**
     * Multi account list (accounts sharing an IP). Request action=known|unknown with id (MULTI id) and optionally userID: marks the
     * IP group, or only one user in it, as allowed or not (MULTI / MULTI_TO_USERS `allowed`), logs it (type 7) and redirects. Otherwise lists
     * the IP groups with their users, mail and last activity.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        if (isset($this->state->requestQuery['action'])) {
            $id = (int) $this->state->requestQuery['id'];
            $allowed = $this->state->requestQuery['action'] === 'known' ? 1 : 0;

            if (!isset($this->state->requestQuery['userID'])) {
                $sql = "SELECT * FROM %%MULTI%% WHERE `multiID` = :multiID;";
                $old = $db->selectSingle($sql, [':multiID' => $id]);

                $sql = "UPDATE %%MULTI%% SET `allowed` = :allowed WHERE `multiID` = :multiID;";
                $db->update($sql, [
                    ':multiID' => $id,
                    ':allowed' => $allowed,
                ]);
            } else {
                $userID = (int) $this->state->requestQuery['userID'];
                $sql = "SELECT * FROM %%MULTI_TO_USERS%% WHERE `multiID` = :multiID AND userID = :userID;";
                $old = $db->selectSingle($sql, [
                    ':multiID' => $id,
                    ':userID'  => $userID,
                ]);

                $sql = "UPDATE %%MULTI_TO_USERS%% SET `allowed` = :allowed WHERE `multiID` = :multiID AND userID = :userID;";
                $db->update($sql, [
                    ':multiID' => $id,
                    ':userID'  => $userID,
                    ':allowed' => $allowed,
                ]);
            }

            $new = $old;
            $new['allowed'] = $allowed;

            $LOG = new Log($this->db, $this->configs, $this->state, 7);
            $LOG->target = $id;
            $LOG->old = $old;
            $LOG->new = $new;
            $LOG->save();

            $this->http()->redirectTo("admin?page=multiIP");
        }

        $sql = "SELECT * FROM %%MULTI%% WHERE universe = :universe ORDER BY `lastActivity`";
        $multis = $db->select($sql, [':universe' => $this->universe()->getEmulated()]);
        $IPs = [];
        foreach ($multis as $multiEntry) {
            $sql = "SELECT u.`id`, u.`username`, a.`email`, u.`register_time`, u.`onlinetime`, u.`user_lastip`, mu.`lastActivity`, mu.`allowed`, u.`bana`
            FROM %%USERS%% AS u
            JOIN %%MULTI_TO_USERS%% AS mu ON mu.userID = u.id
            JOIN %%ACCOUNTS_TO_USERS%% AS atu ON atu.user_id = u.id
            JOIN %%ACCOUNTS%% AS a ON a.id = atu.account_id
            WHERE mu.`multiID` = :multiID;";
            $multiUsers = $db->select($sql, [
                ':multiID' => $multiEntry['multiID'],
            ]);

            if (empty($multiUsers)) {
                continue;
            }

            $IPs[$multiEntry['multiID']] = [];
            $IPs[$multiEntry['multiID']]['multi_ip'] = $multiEntry['multi_ip'];
            $IPs[$multiEntry['multiID']]['lastActivity'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $multiEntry['lastActivity']);
            $IPs[$multiEntry['multiID']]['allowed'] = $multiEntry['allowed'];
            $IPs[$multiEntry['multiID']]['allAllowed'] = $multiEntry['allowed'];
            $IPs[$multiEntry['multiID']]['allBanned'] = true; // assume true
            $IPs[$multiEntry['multiID']]['users'] = [];

            foreach ($multiUsers as $multiUser) {
                $multiUser['register_time'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $multiUser['register_time']);
                $multiUser['onlinetime'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $multiUser['onlinetime']);
                $multiUser['lastActivity'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $multiUser['lastActivity']);

                $IPs[$multiEntry['multiID']]['allAllowed'] &= $multiUser['allowed'] == 1;
                $IPs[$multiEntry['multiID']]['allBanned'] &= $multiUser['bana'] == 1;
                $IPs[$multiEntry['multiID']]['users'][$multiUser['id']] = $multiUser;
            }
        }

        $this->assign([
            'multiGroups'  => $IPs,
        ]);
        return $this->render('admin/MultiIPs.html.twig');
    }
}

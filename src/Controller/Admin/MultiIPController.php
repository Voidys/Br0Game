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

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Log;
use App\Game\Core\Universe;

/**
 * Multi account list (accounts sharing an IP address); entries can be marked as known or unknown.
 */
class MultiIPController extends AbstractAdminController
{
    /**
     * Multi account list (accounts sharing an IP): the IP groups with their users, mail and last activity.
     */
    #[GameRoute('admin', 'admin/multi-ip', 'multiIP')]
    public function show(): Response
    {
        return $this->render('admin/MultiIPs.html.twig', [
            'multiGroups'  => $this->multiGroups(),
        ]);
    }

    /**
     * Marks one IP group (`id`, MULTI id) or a single user of it (`userID`) as known/allowed (`action` known) or not (unknown)
     * (MULTI / MULTI_TO_USERS `allowed`), writes an admin log entry (type 7) with the old and new row and redirects back to the list.
     */
    #[GameRoute('admin', 'admin/multi-ip/{id}/{action}', 'multiIP', 'mark', ['POST'], ['id' => '\d+', 'action' => 'known|unknown'])]
    public function mark(): Response
    {
        $db = $this->db;

        $id = $this->input()->int('id');
        $allowed = $this->input()->string('action') === 'known' ? 1 : 0;

        if (!$this->input()->has('userID')) {
            $sql = "SELECT * FROM %%MULTI%% WHERE `multiID` = :multiID;";
            $old = $db->selectSingle($sql, [':multiID' => $id]);

            $sql = "UPDATE %%MULTI%% SET `allowed` = :allowed WHERE `multiID` = :multiID;";
            $db->update($sql, [
                ':multiID' => $id,
                ':allowed' => $allowed,
            ]);
        } else {
            $userID = $this->input()->int('userID');
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

        return $this->redirectToRoute('admin_multi_ip_show');
    }

    /**
     * The IP groups of the emulated universe that still have users: IP, last activity and per user the dates, mail and
     * flags; `allAllowed` / `allBanned` summarize the group.
     *
     * @return array<int, array<string, mixed>> groups by multi id
     */
    private function multiGroups(): array
    {
        $LNG = $this->state->LNG;
        $db = $this->db;

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

        return $IPs;
    }
}

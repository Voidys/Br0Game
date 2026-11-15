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

use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;

/** Lists pending account activations and deletes entries. */
#[\App\Routing\GamePage('admin.php', 'active')]
#[\App\Routing\GamePage('admin.php', 'active', uniPrefix: true)]
class ActiveController extends AbstractAdminController
{
    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $db = $this->db;

        $id = $this->http()->_GP('id', 0);
        $action = $this->http()->_GP('action', '');
        if ($action == 'delete' && !empty($id)) {
            $sql = "DELETE FROM %%ACCOUNTS_VALID%% WHERE `validationID` = :validationID;";
            $db->delete($sql, [':validationID' => $id]);
        }

        $sql = "SELECT * FROM %%ACCOUNTS_VALID%% ORDER BY validationID ASC;";
        $accounts = $db->select($sql);
        foreach ($accounts as &$account) {
            $account['date'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $account['date'], $USER['timezone']);
        }

        $this->assign([
            'accounts'     => $accounts,
            'uni'          => $this->universe()->getEmulated(),
        ]);
        return $this->render('admin/ActivePage.html.twig');
    }
}

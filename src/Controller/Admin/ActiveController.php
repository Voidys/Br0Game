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

/** Lists pending account activations and deletes entries. */
class ActiveController extends AbstractAdminController
{
    /**
     * Deletes the pending registration `id` (validation id) and returns to the list.
     */
    #[GameRoute('admin', 'admin/active/delete', 'active', 'delete', ['POST'])]
    public function delete(): Response
    {
        $id = $this->input()->int('id');
        if (!empty($id)) {
            $sql = "DELETE FROM %%ACCOUNTS_VALID%% WHERE `validationID` = :validationID;";
            $this->db->delete($sql, [':validationID' => $id]);
        }

        return $this->show();
    }

    /**
     * Pending (not yet verified) registrations from ACCOUNTS_VALID with their registration date in the admin's timezone.
     */
    #[GameRoute('admin', 'admin/active', 'active')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;

        $sql = "SELECT * FROM %%ACCOUNTS_VALID%% ORDER BY validationID ASC;";
        $accounts = $db->select($sql);
        foreach ($accounts as &$account) {
            $account['date'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $account['date'], $USER['timezone']);
        }

        return $this->render('admin/ActivePage.html.twig', [
            'accounts'     => $accounts,
            'uni'          => $this->universe()->getEmulated(),
        ]);
    }
}

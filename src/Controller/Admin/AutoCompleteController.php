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
use App\Game\Core\Universe;

/** JSON username search for the admin autocomplete fields. */
#[\App\Routing\GamePage('admin.php', 'autoComplete')]
#[\App\Routing\GamePage('admin.php', 'autoComplete', uniPrefix: true)]
class AutoCompleteController extends AbstractAdminController
{
    /**
     * JSON autocomplete for admin player fields. Request: term (username fragment, or `#` + id for an exact id lookup).
     * Returns up to 20 players of the emulated universe, exact and prefix matches first; an empty term returns [].
     */
    public function show(): void
    {
        $searchText = $this->http()->_GP('term', '', UTF8_SUPPORT);
        $searchList = [];

        $db = $this->db;
        if (empty($searchText) || $searchText === '#') {
            echo json_encode([]);
            throw new \App\Game\Core\EndOfRequest();
        }

        if (substr($searchText, 0, 1) === '#') {
            $searchText = (int) substr($searchText, 1);
            $where = 'id = :searchText';
            $orderBy = ' ORDER BY id ASC';
        } else {
            $searchText = '%' . $searchText . '%';
            $where = "username LIKE :searchText";
            $orderBy = " ORDER BY (IF(username = :searchText, 1, 0) + IF(username LIKE :searchText, 1, 0)) DESC, username";
        }

        $userResult = $db->select("SELECT id, username FROM %%USERS%% WHERE universe = :universe AND " . $where . $orderBy . " LIMIT 20", [
            ':searchText' => $searchText,
            ':universe' => $this->universe()->getEmulated(),
        ]);
        foreach ($userResult as $userRow) {
            $searchList[] = [
                'label' => $userRow['username'] . ' (ID:' . $userRow['id'] . ')',
                'value' => $userRow['username'],
            ];
        }

        echo json_encode($searchList);
        throw new \App\Game\Core\EndOfRequest();
    }
}

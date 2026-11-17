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

use App\Game\Core\Universe;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** JSON username search for the admin autocomplete fields. */
class AutoCompleteController extends AbstractAdminController
{
    /**
     * JSON autocomplete for admin player fields. Request: term (username fragment, or `#` + id for an exact id lookup).
     * Returns up to 20 players of the emulated universe, exact and prefix matches first; an empty term returns [].
     */
    #[GameRoute('admin', 'admin/autocomplete', 'autoComplete')]
    public function show(): Response
    {
        $searchText = $this->input()->string('term', '', UTF8_SUPPORT);
        $searchList = [];

        $db = $this->db;
        if (empty($searchText) || $searchText === '#') {
            return $this->jsonResponse([]);
        }

        $qb = $db->qb()
            ->select('id', 'username')
            ->from($db->table('USERS'))
            ->where('universe = :universe')
            ->setParameter('universe', $this->universe()->getEmulated())
            ->setMaxResults(20);
        if (substr($searchText, 0, 1) === '#') {
            // exact id lookup
            $qb->andWhere('id = :searchText')->setParameter('searchText', (int) substr($searchText, 1))
                ->orderBy('id', 'ASC');
        } else {
            // name fragment: exact and prefix-style matches first, then by name
            $qb->andWhere('username LIKE :searchText')->setParameter('searchText', '%' . $searchText . '%')
                ->orderBy('(IF(username = :searchText, 1, 0) + IF(username LIKE :searchText, 1, 0))', 'DESC')
                ->addOrderBy('username');
        }

        $userResult = $db->fetchAll($qb);
        foreach ($userResult as $userRow) {
            $searchList[] = [
                'label' => $userRow['username'] . ' (ID:' . $userRow['id'] . ')',
                'value' => $userRow['username'],
            ];
        }

        return $this->jsonResponse($searchList);
    }
}

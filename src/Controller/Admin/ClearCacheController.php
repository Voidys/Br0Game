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

/** Clears the game caches and returns to the maintenance page. */
class ClearCacheController extends AbstractAdminController
{
    /**
     * Clears the game caches and shows a message (via Maintenance::clearCache).
     */
    #[GameRoute('admin', 'admin/clear-cache', 'clearCache', 'show', ['POST'])]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $this->maintenance()->clearCache();

        return $this->messageResponse($LNG['cc_cache_clear'], $this->router->generate('admin_maintenance_show'), 3);
    }
}

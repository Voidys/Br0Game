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

use App\Game\Core\Singleton;

/** Clears the game caches and returns to the maintenance page. */
#[\App\Routing\GamePage('admin.php', 'clearCache')]
#[\App\Routing\GamePage('admin.php', 'clearCache', uniPrefix: true)]
class ClearCacheController extends AbstractAdminController
{
    /**
     * Clears the game caches and shows a message (via Maintenance::clearCache).
     */
    public function show(): never
    {
        $LNG = $this->state->LNG;
        $this->maintenance()->clearCache();
        $this->message($LNG['cc_cache_clear'], 'admin?page=maintenance', 3);
    }
}

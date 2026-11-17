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

/** Former admin top navigation frame: redirects to the dashboard. */
#[\App\Routing\GamePage('admin', 'topnav')]
class TopnavController extends AbstractAdminController
{
    /** former top navigation frame: the admin layout now carries menu and navigation on every page */
    public function show(): void
    {
        $this->http()->redirectTo('admin?page=overview');
    }
}

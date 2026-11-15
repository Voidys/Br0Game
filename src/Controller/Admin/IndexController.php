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

/** Admin entry point: redirects to the dashboard (the layout carries menu and navigation). */
#[\App\Routing\GamePage('admin.php', 'index', default: true)]
#[\App\Routing\GamePage('admin.php', 'index', default: true, uniPrefix: true)]
class IndexController extends AbstractAdminController
{
    /** admin entry (formerly the frameset): the admin layout now carries menu and navigation on every page */
    public function show(): void
    {
        $this->http()->redirectTo('admin?page=overview');
    }
}

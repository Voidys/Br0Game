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

use App\Game\Core\Config;
use App\Game\Core\Singleton;
use Symfony\Component\HttpFoundation\Response;

/** Shows an admin error message. */
#[\App\Routing\GamePage('admin.php', 'error')]
#[\App\Routing\GamePage('admin.php', 'error', uniPrefix: true)]
class ErrorController extends AbstractAdminController
{
    protected $message = '';
    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $this->assign([
            'game_name'    => $this->configs->get()->game_name,
            'adm_cp_title' => $LNG['adm_cp_title'],
            'message' => $this->message,
        ]);

        return $this->render('admin/ShowErrorPage.html.twig');
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }


}

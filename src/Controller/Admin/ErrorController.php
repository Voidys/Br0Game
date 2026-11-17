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

use Symfony\Component\HttpFoundation\Response;

/** Shows an admin error message. */
class ErrorController extends AbstractAdminController
{
    protected $message = '';
    /**
     * Renders an error page inside the admin shell.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        return $this->render('admin/ShowErrorPage.html.twig', [
            'game_name'    => $this->configs->get()->game_name,
            'adm_cp_title' => $LNG['adm_cp_title'],
            'message' => $this->message,
        ]);
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }


}

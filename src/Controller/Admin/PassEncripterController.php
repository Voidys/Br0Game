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
use App\Game\Core\AccountUtil;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;

/**
 * Shows the stored password hash for a given plain password.
 */
#[\App\Routing\GamePage('admin.php', 'passEncripter')]
#[\App\Routing\GamePage('admin.php', 'passEncripter', uniPrefix: true)]
class PassEncripterController extends AbstractAdminController
{
    /**
     * Helper: shows the password hash the game would store for the plain text in `md5q` (for manual database edits).
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $Password = $this->http()->_GP('md5q', '', true);

        $this->assign([
            'md5_md5'          => $Password,
            'md5_enc'          => $this->accountUtil()->cryptPassword($Password),
            'et_md5_encripter' => $LNG['et_md5_encripter'],
            'et_encript'       => $LNG['et_encript'],
            'et_result'        => $LNG['et_result'],
            'et_pass'          => $LNG['et_pass'],
        ]);

        return $this->render('admin/PassEncripterPage.html.twig');
    }
}

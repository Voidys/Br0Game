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

namespace App\Controller\Login;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Public ban list. */
#[\App\Controller\RequireModule('MODULE_BANLIST')]
class BanListController extends AbstractLoginController
{
    use \App\Controller\Shared\BanListPage;

    /**
     * Ban list of the universe; start and end of the bans in the universe timezone.
     */
    #[GameRoute('index', 'banlist', 'banList')]
    public function show(): Response
    {
        return $this->render('login/page.banList.default.html.twig', [
            'universeSelect' => $this->getUniverseSelector(),
            ...$this->banListData($this->configs->get()->timezone),
        ]);
    }
}

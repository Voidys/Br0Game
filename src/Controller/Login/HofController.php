<?php

/**
 *  2Moons
 *   by Timo_Ka 2024
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package pr0game
 * @copyright 2024 Timo_Ka
 * @licence MIT
 * @version 1.8.0
 * @link https://codeberg.org/pr0game/pr0game
 */

namespace App\Controller\Login;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\HTTP;

/** Public hall of fame: the archived final rankings of past universes (uni3, uni4, uni7). */
#[\App\Routing\GamePage('index.php', 'hoF')]
#[\App\Routing\GamePage('index.php', 'hoF', uniPrefix: true)]
class HofController extends AbstractLoginController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();

    }

    public function show(): Response
    {

        $universeSelect    = ['uni3' => 'uni3', 'uni4' => 'uni4', 'uni7' => 'uni7'];
        $universe          = $this->http()->_GP('universe', 'uni7');

        $this->assign([
            'universe'          => $universe ?? "uni7",
            'universeSelect'    => $universeSelect,
        ]);
        if ($universe == 'uni3') {
            return $this->render('login/page.HoF3.default.html.twig');
        } elseif ($universe == 'uni4') {
            return $this->render('login/page.HoF4.default.html.twig');
        } else {
            return $this->render('login/page.HoF7.default.html.twig');
        }

    }

}

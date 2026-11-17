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

use App\Game\Admin\ClosureAdminResponder;
use App\Game\Admin\Creator\AccountCreator;
use App\Game\Admin\Creator\MoonCreator;
use App\Game\Admin\Creator\PlanetCreator;
use App\Game\Admin\Creator\TestDataCreator;
use App\Game\Admin\Creator\UserCreator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates accounts, players, moons, planets and test data. Each form (`mode=<tab>`) is handled by its class in
 * App\Game\Admin\Creator; this controller picks the tab.
 */
#[\App\Routing\GamePage('admin', 'creator')]
class CreatorController extends AbstractAdminController
{
    /**
     * Creator page (admin?page=creator). `mode` selects the tab handler: account, user, moon, planet or testData (classes in App\Game\Admin\Creator,
     * driven through a ClosureAdminResponder); without a known mode the tab overview template is rendered.
     */
    public function show(): Response
    {
        if (empty($this->state->requestQuery['mode'])) {
            $this->state->requestQuery['mode'] = $this->state->requestQuery['page'];
        }
        $responder = new ClosureAdminResponder(
            fn(string|\Twig\Markup $message, string|false $redirect, int $delay, bool $fatal) => $this->message($message, $redirect, $delay, $fatal),
            fn(array $variables) => $this->assign($variables),
            fn(string $template): Response => $this->render($template),
        );
        $tab = match ($this->state->requestQuery['mode']) {
            'account' => AccountCreator::class,
            'user' => UserCreator::class,
            'moon' => MoonCreator::class,
            'planet' => PlanetCreator::class,
            'testData' => TestDataCreator::class,
            default => null,
        };
        if ($tab === null) {
            return $this->render('admin/CreatePage.html.twig');
        }

        return (new $tab($this->db, $this->configs, $this->state, $responder))->handle();
    }
}

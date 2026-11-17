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
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates accounts, players, moons, planets and test data. Each form (`admin/creator/<tab>`) is handled by its class in
 * App\Game\Admin\Creator; this controller picks the tab.
 */
class CreatorController extends AbstractAdminController
{
    /**
     * Creator page (admin/creator): the tab overview.
     */
    #[GameRoute('admin', 'admin/creator', 'creator')]
    public function show(): Response
    {
        return $this->render('admin/CreatePage.html.twig');
    }

    /**
     * One creator tab: account, user, moon, planet or test-data (classes in App\Game\Admin\Creator, driven through a
     * ClosureAdminResponder). GET shows the form, POST creates the object.
     */
    #[GameRoute('admin', 'admin/creator/{tab}', 'creator', 'tab', requirements: ['tab' => 'account|user|moon|planet|test-data'])]
    #[GameRoute('admin', 'admin/creator/{tab}', 'creator', 'save', ['POST'], ['tab' => 'account|user|moon|planet|test-data'])]
    public function tab(): Response
    {
        $responder = new ClosureAdminResponder(
            fn(string|\Twig\Markup $message, string|false $redirect, int $delay, bool $fatal) => $this->message($message, $redirect, $delay, $fatal),
            fn(array $variables) => $this->assign($variables),
            fn(string $template): Response => $this->render($template),
        );
        $tab = match ($this->input()->string('tab')) {
            'account' => AccountCreator::class,
            'user' => UserCreator::class,
            'moon' => MoonCreator::class,
            'planet' => PlanetCreator::class,
            'test-data' => TestDataCreator::class,
            default => throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(),
        };

        return (new $tab($this->db, $this->configs, $this->state, $responder))->handle();
    }
}

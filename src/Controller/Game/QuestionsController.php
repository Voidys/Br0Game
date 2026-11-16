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

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;

/**
 * FAQ pages.
 */
#[\App\Routing\GamePage('game.php', 'questions')]
#[\App\Routing\GamePage('game.php', 'questions', uniPrefix: true)]
class QuestionsController extends AbstractGameController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * FAQ category overview.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;

        $LNG->includeData(['FAQ']);

        return $this->render('game/page.questions.default.html.twig');
    }

    /**
     * One FAQ entry (request: categoryID, questionID); unknown ids redirect to the overview.
     */
    public function single(): Response
    {
        $LNG = $this->state->LNG;

        $LNG->includeData(['FAQ']);

        $categoryID	= $this->http()->_GP('categoryID', 0);
        $questionID	= $this->http()->_GP('questionID', 0);

        if (!isset($LNG['questions'][$categoryID][$questionID])) {
            $this->http()->redirectTo('game?page=questions');
        }

        $this->assign([
            'questionRow'	=> $LNG['questions'][$categoryID][$questionID],
        ]);
        return $this->render('game/page.questions.single.html.twig');
    }
}

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

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * FAQ pages.
 */
class QuestionsController extends AbstractGameController
{
    /**
     * FAQ category overview.
     */
    #[GameRoute('game', 'game/faq', 'questions')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;

        $LNG->includeData(['FAQ']);

        return $this->render('game/page.questions.default.html.twig');
    }

    /**
     * One FAQ entry (request: categoryID, questionID); unknown ids redirect to the overview.
     */
    #[GameRoute('game', 'game/faq/{categoryID}/{questionID}', 'questions', 'single', requirements: ['categoryID' => '\d+', 'questionID' => '\d+'])]
    public function single(): Response
    {
        $LNG = $this->state->LNG;

        $LNG->includeData(['FAQ']);

        $categoryID	= $this->input()->int('categoryID');
        $questionID	= $this->input()->int('questionID');

        if (!isset($LNG['questions'][$categoryID][$questionID])) {
            return $this->redirectToRoute('game_questions_show');
        }

        return $this->render('game/page.questions.single.html.twig', [
            'questionRow'	=> ['body' => new \Twig\Markup((string) $LNG['questions'][$categoryID][$questionID]['body'], 'UTF-8')] + $LNG['questions'][$categoryID][$questionID],
        ]);
    }
}

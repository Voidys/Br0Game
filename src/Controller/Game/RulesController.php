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
use App\Game\Core\Config;
use App\Game\Core\Singleton;

/**
 * Game rules page.
 */
#[\App\Routing\GamePage('game', 'rules')]
class RulesController extends AbstractGameController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    private \App\Content\ContentPages $contentPages;
    private \App\Content\HtmlContent $htmlContent;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setContentPages(\App\Content\ContentPages $contentPages, \App\Content\HtmlContent $htmlContent): void
    {
        $this->contentPages = $contentPages;
        $this->htmlContent = $htmlContent;
    }

    /**
     * Rules page: the same CMS page as the public /rules (edited row, else the default text unless blank rules were
     * chosen) in the player's language; empty rules show a notice.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $LNG->includeData(['PUBLIC']);
        $page = $this->contentPages->find('rules', $LNG->getLanguage());
        $empty = $this->htmlContent->isEmpty($page['content']);
        $this->assign([
            // sanitized CMS HTML (ContentPages) or the trusted default template
            'rules' => $empty ? '' : new \Twig\Markup($page['content'], 'UTF-8'),
            'rulesEmpty' => $empty,
        ]);

        return $this->render('game/page.rules.default.html.twig');
    }
}

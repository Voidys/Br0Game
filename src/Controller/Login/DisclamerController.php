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

use Symfony\Component\HttpFoundation\Response;

/** Public contact/imprint page, rendered from the CMS page "contacts". */
#[\App\Routing\GamePage('index', 'disclamer')]
class DisclamerController extends AbstractLoginController
{
    private \App\Content\ContentPages $contentPages;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setContentPages(\App\Content\ContentPages $contentPages): void
    {
        $this->contentPages = $contentPages;
    }

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Renders the CMS page "contacts" (imprint) in the public layout.
     */
    public function show(): Response
    {
        $page = $this->contentPages->find('contacts', $this->state->LNG->getLanguage());
        $this->assign([
            'cms_title' => htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8'),
            'cms_content' => new \Twig\Markup($page['content'], 'UTF-8'), // sanitized by ContentPages
        ]);

        return $this->render('login/page.disclamer.default.html.twig');
    }
}

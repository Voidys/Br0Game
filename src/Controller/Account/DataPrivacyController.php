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

namespace App\Controller\Account;

use Symfony\Component\HttpFoundation\Response;
use App\Controller\Login\AbstractLoginController;

/** Data privacy page of the account area, rendered from the CMS page "dataPrivacy". */
#[\App\Routing\GamePage('account.php', 'dataPrivacy')]
#[\App\Routing\GamePage('account.php', 'dataPrivacy', uniPrefix: true)]
class DataPrivacyController extends AbstractLoginController
{
    // A login-area page served under account in the account area (as before).
    public static function getGameMode(): string
    {
        return 'ACCOUNT';
    }

    public static function getEntryScript(): string
    {
        return 'account.php';
    }

    public static $requireModule = 0;

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

    public function show(): Response
    {
        $page = $this->contentPages->find('dataPrivacy', $this->state->LNG->getLanguage());
        $this->assign([
            'cms_title' => htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8'),
            'cms_content' => $page['content'],
        ]);

        return $this->render('account/page.dataPrivacy.default.html.twig');
    }
}

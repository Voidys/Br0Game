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

# Actions not logged: Planet-Edit, Alliance-Edit

namespace App\Controller\Admin;

use App\Game\Admin\AccountEditor\AlliancesTab;
use App\Game\Admin\AccountEditor\BuildingsTab;
use App\Game\Admin\AccountEditor\DefensesTab;
use App\Game\Admin\AccountEditor\PersonalTab;
use App\Game\Admin\AccountEditor\PlanetsTab;
use App\Game\Admin\AccountEditor\ResearchTab;
use App\Game\Admin\AccountEditor\ResourcesTab;
use App\Game\Admin\AccountEditor\ShipsTab;
use App\Game\Admin\ClosureAdminResponder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Edits a player: resources, ships, defenses, buildings, research, personal data, planets and alliance. Each tab
 * (`edit=<tab>`) is handled by its class in App\Game\Admin\AccountEditor; this controller picks the tab.
 */
#[\App\Routing\GamePage('admin.php', 'accountEditor')]
#[\App\Routing\GamePage('admin.php', 'accountEditor', uniPrefix: true)]
class AccountEditorController extends AbstractAdminController
{
    /**
     * Menu of the account editor (admin?page=accounteditor): checks that the acting admin may edit and renders the tab links. The
     * tabs (resources, ships, buildings, research, personal data, planets, alliances ...) are classes under App\Game\Admin\AccountEditor.
     */
    public function show(): Response
    {
        if (!isset($this->state->requestQuery['edit'])) {
            $this->state->requestQuery['edit'] = '';
        }
        $responder = new ClosureAdminResponder(
            fn(string|\Twig\Markup $message, string|false $redirect, int $delay, bool $fatal) => $this->message($message, $redirect, $delay, $fatal),
            fn(array $variables) => $this->assign($variables),
            fn(string $template): Response => $this->render($template),
        );
        $tab = match ($this->state->requestQuery['edit']) {
            'resources' => ResourcesTab::class,
            'ships' => ShipsTab::class,
            'defenses' => DefensesTab::class,
            'buildings' => BuildingsTab::class,
            'researchs' => ResearchTab::class,
            'personal' => PersonalTab::class,
            'planets' => PlanetsTab::class,
            'alliances' => AlliancesTab::class,
            default => null,
        };
        if ($tab === null) {
            return $this->render('admin/AccountEditorPageMenu.html.twig');
        }

        return (new $tab($this->db, $this->configs, $this->state, $responder))->handle();
    }
}

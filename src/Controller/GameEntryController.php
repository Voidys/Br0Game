<?php

namespace App\Controller;

use App\Runtime\Installer;
use App\Runtime\GameKernel;
use App\Runtime\GameResponseFactory;
use App\Routing\GamePage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Game\Core\EndOfRequest;
use App\Game\Core\HTTP;
use App\Game\Core\Session;
use App\Game\Core\Database;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use App\Controller\Account\ErrorController as AccountErrorPage;
use App\Controller\Admin\ErrorController as AdminErrorPage;
use App\Controller\Game\ErrorController as GameErrorPage;
use App\Controller\Login\ErrorController as LoginErrorPage;

/**
 * Remaining entry points of the game under their original URLs:
 * - fallbacks for the start page, game, account and admin when no page controller
 *   matches (the pages themselves are routed to App\Controller\{Login,Game,Account,Admin}\*
 *   via #[GamePage]); they show the area's "page does not exist" error,
 * - CombatReport (report redirect),
 * - the installer.
 *
 * Every URL also works with a /uniN/ prefix (universe N).
 *
 * These routes run outside the Symfony firewall/session (see security.yaml): the game code
 * manages the native PHP session itself.
 */
final class GameEntryController
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        private readonly GameKernel $kernel,
        private readonly GameResponseFactory $responses,
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly Installer $installer,
        // handed to error pages built here: an unknown admin page shows the admin password prompt, which rate-limits
        private readonly \App\Security\AuthRateLimits $rateLimits,
        private readonly \App\Runtime\CanonicalUrl $canonicalUrl,
    ) {}

    /**
     * Fallback for `/` and `/uniN/`: pages of the public area are routed to Login\* controllers, so this only runs for an unknown `?page=` and
     * shows the public "page does not exist" error (LOGIN mode).
     */
    #[Route('/{uni}', name: 'game_login', requirements: ['uni' => GamePage::UNI_REQUIREMENT], defaults: ['uni' => ''])]
    public function login(Request $request, string $uni = ''): Response
    {
        // Login pages are routed to App\Controller\Login\* (#[GamePage]); this only answers unknown pages.
        return $this->page($request, $uni, function (): void {
            $this->kernel->boot('LOGIN', 'index');
            (new LoginErrorPage())->withGameServices($this->db, $this->configs, $this->state)->showError($this->state->LNG['page_doesnt_exist']);
        });
    }

    /**
     * Fallback for `/game` (and `/uniN/game`): boots INGAME mode and shows the "page does not exist" error of the game area. Default page is overview.
     */
    #[Route('/{uni}game', name: 'game_ingame', requirements: ['uni' => GamePage::UNI_REQUIREMENT], defaults: ['uni' => ''])]
    public function game(Request $request, string $uni = ''): Response
    {
        // Game pages are routed to App\Controller\Game\* (#[GamePage]); this only answers unknown pages.
        return $this->page($request, $uni, function (): void {
            $this->kernel->boot('INGAME', 'game');
            (new GameErrorPage())->withGameServices($this->db, $this->configs, $this->state)->showError($this->state->LNG['page_doesnt_exist']);
        });
    }

    /**
     * Fallback for `/account` (and `/uniN/account`): boots ACCOUNT mode and shows the "page does not exist" error of the account lobby.
     */
    #[Route('/{uni}account', name: 'game_account', requirements: ['uni' => GamePage::UNI_REQUIREMENT], defaults: ['uni' => ''])]
    public function account(Request $request, string $uni = ''): Response
    {
        // Account pages are routed to App\Controller\Account\* (#[GamePage]); this only answers unknown pages.
        return $this->page($request, $uni, function (): void {
            $this->kernel->boot('ACCOUNT', 'account');
            (new AccountErrorPage())->withGameServices($this->db, $this->configs, $this->state)->showError($this->state->LNG['page_doesnt_exist']);
        });
    }

    /**
     * Fallback for `/admin` (and `/uniN/admin`): boots ADMIN mode and shows the "page does not exist" admin error after the normal admin entry checks
     * (players are sent to the game, the admin password prompt is shown until the session has admin access).
     */
    #[Route('/{uni}admin', name: 'game_admin', requirements: ['uni' => GamePage::UNI_REQUIREMENT], defaults: ['uni' => ''])]
    public function admin(Request $request, string $uni = ''): Response
    {
        // Admin pages are routed to App\Controller\Admin\* (#[GamePage]); this only answers unknown pages:
        // the error page goes through the same entry checks (player redirect, admin password prompt).
        return $this->page($request, $uni, function (): void {
            $this->kernel->boot('ADMIN', 'admin');

            $errorPage = new AdminErrorPage();
            $errorPage->setGameServices($this->db, $this->configs, $this->state);
            $errorPage->setSecurityServices($this->rateLimits, $this->canonicalUrl);
            $errorPage->initialize();
            $errorPage->setMessage($this->state->LNG['page_doesnt_exist']);
            $errorPage->show();
            throw new EndOfRequest();
        });
    }

    /**
     * Old combat report URL (`/CombatReport?raport=<id>`): redirects to the report page `game?page=raport`.
     */
    #[Route('/{uni}CombatReport', name: 'game_combat_report', requirements: ['uni' => GamePage::UNI_REQUIREMENT], defaults: ['uni' => ''])]
    public function combatReport(Request $request, string $uni = ''): Response
    {
        return $this->page($request, $uni, function (): void {
            $this->kernel->boot('INGAME', 'CombatReport');
            $this->http()->redirectTo('game?page=raport&raport=' . $this->http()->_GP('raport', ''));
        });
    }

    /**
     * Installer (`/install/`): runs UPGRADE mode for `?mode=upgrade`, otherwise INSTALL mode, and lets Installer::run() produce the response.
     */
    #[Route('/install/', name: 'game_install')]
    public function install(Request $request): Response
    {
        return $this->page($request, '', function () use ($request): void {
            // as before: only ?mode=upgrade runs in UPGRADE mode, everything else in INSTALL mode
            $this->kernel->boot($request->query->get('mode') === 'upgrade' ? 'UPGRADE' : 'INSTALL', 'install/index');
            $this->installer->run();
        });
    }

    /**
     * @param callable(): void $run
     */
    private function page(Request $request, string $uni, callable $run): Response
    {
        $uni = substr($uni, 3, -1); // "uni5/" -> "5"
        if ($uni !== '' && !$request->server->has('REDIRECT_UNI')) {
            $request->server->set('REDIRECT_UNI', $uni);
        }

        return $this->responses->run($run);
    }
}

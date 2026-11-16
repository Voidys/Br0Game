<?php

namespace App\Controller;

use App\Runtime\Installer;
use App\Runtime\GameKernel;
use App\Runtime\GameResponseFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
 * - fallbacks for index.php, game, account and admin when no page controller
 *   matches (the pages themselves are routed to App\Controller\{Login,Game,Account,Admin}\*
 *   via #[GamePage]); they show the area's "page does not exist" error,
 * - CombatReport (report redirect),
 * - the installer.
 *
 * Every URL also works with a /uniN/ prefix (universe N). Ported pages with a dedicated
 * Symfony route are redirected there (config/legacy_routes.php).
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
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {}

    /**
     * Fallback for `/`, `/index.php` and `/uniN/`: pages of the public area are routed to Login\* controllers, so this only runs for an unknown `?page=` and
     * shows the public "page does not exist" error (LOGIN mode).
     */
    #[Route('/', name: 'game_login_root')]
    #[Route('/index.php', name: 'game_login')]
    #[Route('/uni{uni}/{_ext}', name: 'game_login_uni', requirements: ['uni' => '\d+', '_ext' => '(?:index\.php)?'], defaults: ['_ext' => ''])]
    public function login(Request $request, ?string $uni = null): Response
    {
        // Login pages are routed to App\Controller\Login\* (#[GamePage]); this only answers unknown pages.
        return $this->page($request, $uni, 'index.php', 'index', function (): void {
            $this->kernel->boot('LOGIN', 'index.php');
            (new LoginErrorPage())->withGameServices($this->db, $this->configs, $this->state)->showError($this->state->LNG['page_doesnt_exist']);
        });
    }

    /**
     * Fallback for `/game` (and `/uniN/game`): boots INGAME mode and shows the "page does not exist" error of the game area. Default page is overview.
     */
    #[Route('/game{_ext}', requirements: ['_ext' => '(?:\.php)?'], defaults: ['_ext' => ''], name: 'game_ingame')]
    #[Route('/uni{uni}/game{_ext}', defaults: ['_ext' => ''], name: 'game_ingame_uni', requirements: ['uni' => '\d+', '_ext' => '(?:\.php)?'])]
    public function game(Request $request, ?string $uni = null): Response
    {
        // Game pages are routed to App\Controller\Game\* (#[GamePage]); this only answers unknown pages.
        return $this->page($request, $uni, 'game.php', 'overview', function (): void {
            $this->kernel->boot('INGAME', 'game.php');
            (new GameErrorPage())->withGameServices($this->db, $this->configs, $this->state)->showError($this->state->LNG['page_doesnt_exist']);
        });
    }

    /**
     * Fallback for `/account` (and `/uniN/account`): boots ACCOUNT mode and shows the "page does not exist" error of the account lobby.
     */
    #[Route('/account{_ext}', requirements: ['_ext' => '(?:\.php)?'], defaults: ['_ext' => ''], name: 'game_account')]
    #[Route('/uni{uni}/account{_ext}', defaults: ['_ext' => ''], name: 'game_account_uni', requirements: ['uni' => '\d+', '_ext' => '(?:\.php)?'])]
    public function account(Request $request, ?string $uni = null): Response
    {
        // Account pages are routed to App\Controller\Account\* (#[GamePage]); this only answers unknown pages.
        return $this->page($request, $uni, 'account.php', 'account', function (): void {
            $this->kernel->boot('ACCOUNT', 'account.php');
            (new AccountErrorPage())->withGameServices($this->db, $this->configs, $this->state)->showError($this->state->LNG['page_doesnt_exist']);
        });
    }

    /**
     * Fallback for `/admin` (and `/uniN/admin`): boots ADMIN mode and shows the "page does not exist" admin error after the normal admin entry checks
     * (players are sent to the game, the admin password prompt is shown until the session has admin access).
     */
    #[Route('/admin{_ext}', requirements: ['_ext' => '(?:\.php)?'], defaults: ['_ext' => ''], name: 'game_admin')]
    #[Route('/uni{uni}/admin{_ext}', defaults: ['_ext' => ''], name: 'game_admin_uni', requirements: ['uni' => '\d+', '_ext' => '(?:\.php)?'])]
    public function admin(Request $request, ?string $uni = null): Response
    {
        // Admin pages are routed to App\Controller\Admin\* (#[GamePage]); this only answers unknown pages:
        // the error page goes through the same entry checks (player redirect, admin password prompt).
        return $this->page($request, $uni, 'admin.php', 'index', function (): void {
            $this->kernel->boot('ADMIN', 'admin.php');

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
    #[Route('/CombatReport{_ext}', requirements: ['_ext' => '(?:\.php)?'], defaults: ['_ext' => ''], name: 'game_combat_report')]
    #[Route('/uni{uni}/CombatReport{_ext}', defaults: ['_ext' => ''], name: 'game_combat_report_uni', requirements: ['uni' => '\d+', '_ext' => '(?:\.php)?'])]
    public function combatReport(Request $request, ?string $uni = null): Response
    {
        return $this->page($request, $uni, 'CombatReport.php', null, function (): void {
            $this->kernel->boot('INGAME', 'CombatReport.php');
            $this->http()->redirectTo('game?page=raport&raport=' . $this->http()->_GP('raport', ''));
        });
    }

    /**
     * Installer (`/install/`): runs UPGRADE mode for `?mode=upgrade`, otherwise INSTALL mode, and lets Installer::run() produce the response.
     */
    #[Route('/install/', name: 'game_install')]
    #[Route('/install/index.php', name: 'game_install_index')]
    public function install(Request $request): Response
    {
        return $this->page($request, null, 'install/index.php', null, function () use ($request): void {
            // as before: only ?mode=upgrade runs in UPGRADE mode, everything else in INSTALL mode
            $this->kernel->boot($request->query->get('mode') === 'upgrade' ? 'UPGRADE' : 'INSTALL', 'install/index.php');
            $this->installer->run();
        });
    }

    /**
     * @param callable(): void $run
     */
    private function page(Request $request, ?string $uni, string $script, ?string $defaultPage, callable $run): Response
    {
        if ($uni !== null && !$request->server->has('REDIRECT_UNI')) {
            $request->server->set('REDIRECT_UNI', $uni);
        }

        $redirect = $this->migratedPageRedirect($request, $script, $defaultPage);
        if ($redirect !== null) {
            return $redirect;
        }

        return $this->responses->run($run);
    }

    /**
     * Looks up `?page=` (or $defaultPage) of $script in config/legacy_routes.php (lower-cased page name) and returns the 308 redirect to the
     * new route, or null when the page was not ported.
     */
    private function migratedPageRedirect(Request $request, string $script, ?string $defaultPage): ?Response
    {
        $routes = require $this->projectDir . '/config/legacy_routes.php';
        $page = $request->query->get('page', $defaultPage);
        $target = is_string($page) ? ($routes[$script][strtolower($page)] ?? null) : null;
        if ($target === null) {
            return null;
        }

        $query = $request->query->all();
        unset($query['page']);

        return new RedirectResponse($target . ($query ? '?' . http_build_query($query) : ''), 308);
    }
}

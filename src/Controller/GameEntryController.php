<?php

namespace App\Controller;

use App\Runtime\Installer;
use App\Runtime\GameKernel;
use App\Runtime\GameResponseFactory;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Remaining entry points of the game under their original URLs (the pages themselves are routed
 * to App\Controller\{Login,Game,Account,Admin}\* via #[GameRoute]):
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
    public function __construct(
        private readonly GameKernel $kernel,
        private readonly GameResponseFactory $responses,
        private readonly Installer $installer,
    ) {}

    /**
     * Old combat report URL (`/CombatReport?raport=<id>`): redirects to the report page `report/<id>`.
     */
    #[Route('/{uni}CombatReport', name: 'game_combat_report', requirements: ['uni' => GameRoute::UNI_REQUIREMENT], defaults: ['uni' => ''])]
    public function combatReport(Request $request): Response
    {
        // relative to the universe directory: /CombatReport -> /report/<id>, /uniN/CombatReport -> /uniN/report/<id>
        return new RedirectResponse('report/' . rawurlencode($request->query->getString('raport')));
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

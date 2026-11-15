<?php

declare(strict_types=1);

namespace App\View;

use App\Controller\Admin\AdminShell;
use App\Runtime\SafeFile;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Markup;

/**
 * Renders the game area templates (templates/{game,login,account,admin,install}/) with Symfony's
 * Twig environment.
 *
 * Page controllers do not use it directly: their base classes collect the page variables and add
 * the area's layout data (App\Controller\RendersPages). It is the renderer for game code outside
 * a page controller: the installer, the admin message page and HTML that is stored rather than
 * sent (spy reports).
 *
 * Template names are paths below templates/, e.g. "game/page.overview.default.html.twig".
 * Escaping: see App\Twig\GameEscaping; the `LNG` global comes from App\Twig\TemplateExtension.
 */
final class GameView
{
    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context = []): string
    {
        return $this->twig()->render($template, $context);
    }

    /** @param array<string, mixed> $context */
    public function response(string $template, array $context = [], int $status = Response::HTTP_OK): Response
    {
        return new Response($this->render($template, $context), $status);
    }

    /**
     * The standalone message page of the admin area and the installer: a notice with an optional
     * redirect after $delay seconds. A plain string message is escaped; pass Twig\Markup for HTML.
     *
     * @param string|false $redirect target URL; false or '' = no redirect
     */
    public function messagePage(string|Markup $message, string|false $redirect = false, int $delay = 3, bool $fatal = false): Response
    {
        $LNG = $this->state->LNG;
        $context = [
            'mes' => $message,
            'Fatal' => $fatal,
            'goto' => $redirect,
            'gotoinsec' => $delay,
            'fcm_info' => $LNG['fcm_info'],
        ];

        $mode = $this->state->runtime->mode;
        if ($mode === 'INSTALL' || $mode === 'UPGRADE') {
            return $this->response('install/error_message_body.html.twig', $context + ['lang' => $LNG->getLanguage()]);
        }

        // admin area (the only other area with a standalone message page)
        $shell = new AdminShell($this->db, $this->configs, $this->state);

        return $this->response('admin/error_message_body.html.twig', $context + $shell->pageData());
    }

    /** removes the compiled templates (admin "clear cache", maintenance) */
    public function clearCompiledTemplates(): void
    {
        $dir = $this->twig()->getCache(false);
        if (!is_string($dir) || !is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            SafeFile::remove($file->getPathname());
        }
    }

    /** Symfony's Twig environment, handed to the game state by App\Runtime\GameKernel */
    private function twig(): Environment
    {
        return $this->state->twig ?? throw new \LogicException('No Twig environment: the game runs through App\\Runtime\\GameKernel.');
    }
}

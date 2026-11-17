<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Community\HallOfFame;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Hall of fame of the universe the admin currently acts in: export (download), import (upload, replaces) and clear.
 */
class HallOfFameController extends AbstractAdminController
{
    private HallOfFame $hallOfFame;

    #[Required]
    public function setHallOfFame(HallOfFame $hallOfFame): void
    {
        $this->hallOfFame = $hallOfFame;
    }

    /**
     * Hall of fame admin page: status and forms (export, import, clear). Import and clear redirect back with a flash message.
     */
    #[GameRoute('admin', 'admin/hall-of-fame', 'hallOfFame')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $uni = (int) $this->universe()->getEmulated();

        $doc = $this->hallOfFame->find($uni);
        $flash = $this->session()->get('hof_flash');
        $this->session()->remove('hof_flash');

        return $this->render('admin/HallOfFamePage.html.twig', [
            'hofUni' => $uni,
            'hofExists' => $doc !== null,
            'hofEntries' => $doc !== null ? HallOfFame::countEntries($doc) : 0,
            'hofUpdated' => $doc !== null ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], $doc['updated_at'], $this->state->USER['timezone']) : '',
            'hofFlash' => is_array($flash) ? $flash : null,
        ]);
    }

    /** POST: downloads hall-of-fame-uni<N>.json (POST because a GET with a state-changing look is not worth a token rule). */
    #[GameRoute('admin', 'admin/hall-of-fame/export', 'hallOfFame', 'export', ['POST'])]
    public function export(): Response
    {
        $uni = (int) $this->universe()->getEmulated();
        $json = $this->hallOfFame->export($uni);
        if ($json === null) {
            return $this->redirectToRoute('admin_hall_of_fame_show');
        }

        return new Response($json, Response::HTTP_OK, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'hall-of-fame-uni' . $uni . '.json'),
        ]);
    }

    /** POST: imports the uploaded file (replaces the hall of fame of the universe), then redirects back with a flash message. */
    #[GameRoute('admin', 'admin/hall-of-fame/import', 'hallOfFame', 'import', ['POST'])]
    public function import(): Response
    {
        $this->doImport((int) $this->universe()->getEmulated());

        return $this->redirectToRoute('admin_hall_of_fame_show');
    }

    /** POST: clears the hall of fame of the universe, then redirects back with a flash message. */
    #[GameRoute('admin', 'admin/hall-of-fame/clear', 'hallOfFame', 'clear', ['POST'])]
    public function clear(): Response
    {
        $this->hallOfFame->clear((int) $this->universe()->getEmulated());
        $this->flash($this->state->LNG['hof_cleared']);

        return $this->redirectToRoute('admin_hall_of_fame_show');
    }

    /**
     * Imports the uploaded hall-of-fame JSON file into the universe. Problems (no or too large file, invalid
     * content) and success are reported through the flash message instead of an exception.
     */
    private function doImport(int $uni): void
    {
        $LNG = $this->state->LNG;
        $file = $this->state->request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->flash($LNG['hof_error_nofile'], false);

            return;
        }
        if ($file->getSize() > HallOfFame::MAX_BYTES) {
            $this->flash($LNG['hof_error_invalid'] . ' file too large (max 1 MB)', false);

            return;
        }
        try {
            $this->hallOfFame->import($uni, (string) file_get_contents($file->getPathname()), $this->state->runtime->timestamp);
        } catch (\InvalidArgumentException $e) {
            $this->flash($LNG['hof_error_invalid'] . ' ' . $e->getMessage(), false);

            return;
        }
        $this->flash($LNG['hof_imported']);
    }

    private function session(): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        return $this->sessions()->symfonySession();
    }

    private function flash(string $message, bool $ok = true): void
    {
        $this->session()->set('hof_flash', ['message' => $message, 'ok' => $ok]);
    }
}

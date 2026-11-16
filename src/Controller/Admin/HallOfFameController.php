<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Community\HallOfFame;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Hall of fame of the universe the admin currently acts in: export (download), import (upload, replaces) and clear.
 */
#[\App\Routing\GamePage('admin.php', 'hallOfFame')]
#[\App\Routing\GamePage('admin.php', 'hallOfFame', uniPrefix: true)]
class HallOfFameController extends AbstractAdminController
{
    private HallOfFame $hallOfFame;

    #[Required]
    public function setHallOfFame(HallOfFame $hallOfFame): void
    {
        $this->hallOfFame = $hallOfFame;
    }

    /**
     * Hall of fame admin page. POST action=export downloads hall-of-fame-uni<N>.json. POST action=import (file) or action=clear (the global CSRF layer
     * checks the token; export is POST too because a GET with `action` counts as state-changing there); they redirect back with done=imported|cleared or a flash error. Default: status and forms.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $uni = (int) $this->universe()->getEmulated();
        $action = (string) $this->http()->_GP('action', '');
        $isPost = ($this->state->requestServer['REQUEST_METHOD'] ?? '') === 'POST';

        if ($action === 'export' && $isPost) {
            $json = $this->hallOfFame->export($uni);
            if ($json === null) {
                $this->http()->redirectTo('admin?page=hallOfFame');
            }

            return new Response($json, Response::HTTP_OK, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'hall-of-fame-uni' . $uni . '.json'),
            ]);
        }

        if ($isPost && ($action === 'import' || $action === 'clear')) {
            if ($action === 'clear') {
                $this->hallOfFame->clear($uni);
                $this->flash($LNG['hof_cleared']);
            } else {
                $this->doImport($uni);
            }
            $this->http()->redirectTo('admin?page=hallOfFame');
        }

        $doc = $this->hallOfFame->find($uni);
        $flash = $this->session()->get('hof_flash');
        $this->session()->remove('hof_flash');

        $this->assign([
            'hofUni' => $uni,
            'hofExists' => $doc !== null,
            'hofEntries' => $doc !== null ? HallOfFame::countEntries($doc) : 0,
            'hofUpdated' => $doc !== null ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], $doc['updated_at'], $this->state->USER['timezone']) : '',
            'hofFlash' => is_array($flash) ? $flash : null,
        ]);

        return $this->render('admin/HallOfFamePage.html.twig');
    }

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

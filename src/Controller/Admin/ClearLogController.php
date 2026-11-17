<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Runtime\ProjectPaths;

/**
 * Empties the game error log (var/log/game.log) and returns to the maintenance page. POST only (CsrfPolicy); needs the
 * clear-cache right. The file is truncated, not deleted, so its owner and permissions stay.
 */
#[\App\Routing\GamePage('admin', 'clearLog')]
class ClearLogController extends AbstractAdminController
{
    public const LOG_FILE = 'var/log/game.log';

    protected function construct(): void
    {
        if (!$this->viewHelpers()->allowedTo('ShowClearCachePage')) {
            $this->deny();
        }
    }

    /** truncates the game log and shows a message */
    public function show(): never
    {
        $LNG = $this->state->LNG;
        $file = ProjectPaths::ROOT . self::LOG_FILE;
        $ok = !is_file($file) || (is_writable($file) && file_put_contents($file, '', LOCK_EX) !== false);
        $this->message($ok ? $LNG['adm_maint_log_cleared'] : $LNG['adm_maint_log_failed'], 'admin?page=maintenance', 3);
    }

    /**
     * Size (bytes) and modification time of the game log, null when the file does not exist.
     *
     * @return array{size: int, mtime: int}|null
     */
    public static function logInfo(): ?array
    {
        $file = ProjectPaths::ROOT . self::LOG_FILE;
        clearstatcache(true, $file);
        if (!is_file($file)) {
            return null;
        }

        return ['size' => (int) filesize($file), 'mtime' => (int) filemtime($file)];
    }
}

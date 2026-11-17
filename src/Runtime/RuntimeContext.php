<?php

declare(strict_types=1);

namespace App\Runtime;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Request-scoped runtime facts of the game, captured once per request / console run
 * (formerly the define()d constants TIMESTAMP, MODE, ROOT_PATH, CACHE_PATH, AJAX_REQUEST,
 * HTTPS, PROTOCOL, HTTP_BASE, HTTP_ROOT, HTTP_FILE, HTTP_HOST, HTTP_PATH and GIT_VERSION).
 *
 * Reached through the game state ($state->runtime). The timestamp is frozen at start(), so every
 * calculation of one request sees the same "now", like the old TIMESTAMP constant.
 */
final class RuntimeContext
{
    /** request time (unix seconds), frozen for the whole request */
    public private(set) int $timestamp = 0;

    /** game area: LOGIN, INGAME, ADMIN, ACCOUNT, INSTALL, UPGRADE, CLI */
    public private(set) string $mode = '';

    /** project root with trailing slash */
    public readonly string $rootPath;

    /** game caches (var/game/) */
    public readonly string $cachePath;

    public private(set) bool $ajax = false;
    public private(set) bool $https = false;
    public private(set) string $protocol = 'http://';
    public private(set) string $httpBase = '/';
    public private(set) string $httpRoot = '/';
    public private(set) string $httpFile = '';
    public private(set) string $httpHost = '';
    public private(set) string $httpPath = '';

    /** short commit hash of the checkout, used for asset cache busting */
    public string $gitVersion {
        get => $this->gitVersion ??= $this->readGitVersion();
    }

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
    ) {
        $this->rootPath = rtrim(str_replace('\\', '/', $projectDir), '/') . '/';
        $this->cachePath = $this->rootPath . 'var/game/';
    }

    /** True once start() captured the request facts (timestamp is non-zero). */
    public function isStarted(): bool
    {
        return $this->timestamp !== 0;
    }

    /**
     * Captures the request facts. Runs once per request / console run; later calls are ignored,
     * like re-defining a constant was.
     *
     * @param array<string, mixed> $server server parameters of the request
     * @param array<string, mixed> $input  request input (POST over GET)
     */
    public function start(string $mode, array $server, array $input = []): void
    {
        if ($this->isStarted()) {
            return;
        }

        $this->timestamp = time();
        $this->mode = $mode;
        // ?ajax=1 (XHR) or the modal loader's fragment request (scripts/app/dialog.js)
        $this->ajax = (int) ($input['ajax'] ?? 0) !== 0 || ($server['HTTP_X_GAME_FRAGMENT'] ?? '') === '1';
        $this->https = ($server['HTTPS'] ?? '') === 'on';
        $this->protocol = $this->https ? 'https://' : 'http://';

        $scriptName = (string) ($server['SCRIPT_NAME'] ?? '');
        $this->httpBase = str_replace(['\\', '//'], '/', dirname($scriptName) . '/');
        // the area's clean URL name: game, admin, account, CombatReport; '' for the login (./)
        $this->httpFile = preg_replace('/^index$/', '', basename($scriptName, '.php'));

        if (PHP_SAPI === 'cli') {
            $requestUrl = str_replace([$this->rootPath . 'src/Game', '\\'], ['', '/'], (string) ($server['PHP_SELF'] ?? ''));
            $this->httpHost = '127.0.0.1';
        } else {
            $requestUrl = (string) ($server['REQUEST_URI'] ?? '/');
            $this->httpHost = (string) ($server['HTTP_HOST'] ?? '');
        }

        // directory of the requested page: /uni1/game and /uni1/ both give /uni1/
        $path = (string) parse_url($requestUrl, PHP_URL_PATH);
        $this->httpRoot = substr($path, 0, (int) strrpos($path, '/') + 1) ?: '/';
        $this->httpPath = $this->protocol . $this->httpHost . $this->httpRoot;
    }

    /** First 7 characters of the checked-out commit (reads .git/HEAD, follows a branch ref); '' without a checkout. */
    private function readGitVersion(): string
    {
        $head = SafeFile::read($this->rootPath . '.git/HEAD');
        if ($head === null) {
            return '';
        }

        $head = trim($head);
        $commit = str_starts_with($head, 'ref: ')
            ? trim((string) SafeFile::read($this->rootPath . '.git/' . substr($head, 5)))
            : $head;

        return substr($commit, 0, 7);
    }
}

<?php

namespace App\Twig;

use App\Runtime\GameConfig;
use App\Runtime\LanguagePacks;
use App\Runtime\ProjectPaths;
use App\Runtime\RuntimeContext;
use App\Security\CspNonce;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Game-wide template helpers:
 *   game_config('game_name')  value from the game configuration
 *   languages()               available languages (locale => name)
 *   asset_version             cache-busting version (short git commit, RuntimeContext::$gitVersion)
 *   import_map()              <script type="importmap"> that versions every ES module under
 *                             public/scripts by its mtime, so modules imported by other modules
 *                             (`import './dialog.js'`) are refetched as soon as the file changes
 *   data_island(id, data)     <script type="application/json" id="id"> with the data as JSON: the way a template
 *                             hands data to its page module (read with pageData() from scripts/app/core.js).
 *                             JSON is not executed, so no nonce is needed
 */
final class GameExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly LanguagePacks $languagePacks,
        private readonly RuntimeContext $runtime,
        private readonly CspNonce $nonce,
    ) {}

    /** @return list<TwigFunction> game_config(key), languages(), import_map(), data_island(id, data) */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('game_config', $this->config->get(...)),
            new TwigFunction('languages', $this->languagePacks->getNames(...)),
            new TwigFunction('import_map', $this->importMap(...)),
            new TwigFunction('data_island', $this->dataIsland(...), ['is_safe' => ['all']]),
        ];
    }

    /**
     * `<script type="importmap">` mapping every file under public/scripts to the same path with `?v=<mtime>`, so ES
     * module imports are cache-busted per file. Must precede every module script of the page (import maps apply to
     * later module loads only). The directory scan runs once per process; the tag carries the CSP nonce.
     */
    public function importMap(): Markup
    {
        static $json = null;
        if ($json !== null) {
            return $this->importMapTag($json);
        }

        $root = ProjectPaths::ROOT . 'public/scripts/';
        $imports = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }
            $path = './scripts/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
            $imports[$path] = $path . '?v=' . $file->getMTime();
        }
        ksort($imports);

        $json = json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        return $this->importMapTag($json);
    }

    /**
     * JSON data island. `<`, `>`, `&` and quotes are \u-escaped, so no value can close the script element
     * or leak markup; translations (trusted Markup) and plain values are encoded as they are.
     */
    public function dataIsland(string $id, mixed $data): string
    {
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return '<script type="application/json" id="' . htmlspecialchars($id, ENT_QUOTES) . '">' . ($json === false ? 'null' : $json) . '</script>';
    }

    /** the importmap is an inline script, so it carries the Content-Security-Policy nonce of the request */
    private function importMapTag(string $json): Markup
    {
        return new Markup('<script type="importmap" nonce="' . $this->nonce->value() . '">' . $json . '</script>', 'UTF-8');
    }

    /** @return array{asset_version: string} short git hash, appended to static asset URLs for cache busting */
    public function getGlobals(): array
    {
        return ['asset_version' => $this->runtime->gitVersion];
    }
}

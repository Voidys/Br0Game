<?php

namespace App\Twig;

use App\Runtime\GameConfig;
use App\Runtime\LanguagePacks;
use App\Runtime\ProjectPaths;
use App\Runtime\RuntimeContext;
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
 */
final class GameExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly LanguagePacks $languagePacks,
        private readonly RuntimeContext $runtime,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('game_config', $this->config->get(...)),
            new TwigFunction('languages', $this->languagePacks->getNames(...)),
            new TwigFunction('import_map', $this->importMap(...)),
        ];
    }

    /** must precede every module script of the page (import maps apply to later module loads only) */
    public function importMap(): Markup
    {
        static $html = null;
        if ($html !== null) {
            return $html;
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

        return $html = new Markup('<script type="importmap">'
            . json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
            . '</script>', 'UTF-8');
    }

    public function getGlobals(): array
    {
        return ['asset_version' => $this->runtime->gitVersion];
    }
}

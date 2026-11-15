<?php

declare(strict_types=1);

namespace App\Game\Core;

use App\Runtime\ProjectPaths;

/**
 * Resolves theme image paths (`gebaeude/229.gif`, `planeten/varianten/v2_storm/x.webp`) to a URL.
 *
 * Lookup order for a theme (image theme):
 *   1. the theme's own folder        public/styles/theme/<theme>/
 *   2. each artwork set the theme declares in its style.cfg (`'assetSets' => ['nebula', 'classic']`, in order)
 *                                    public/styles/resource/theme-shared/sets/<set>/
 *   3. the files every theme has in common
 *                                    public/styles/resource/theme-shared/common/
 *   Each folder is tried with the exact path first, then with the extension replaced by .webp (images are
 *   shipped as WebP, templates may still ask for x.gif / x.jpg / x.png).
 *   4. nothing found: the theme path is returned, so a missing image shows up as before (404).
 *
 * A new theme overrides any image by putting a file at the same relative path into its folder, or reuses
 * existing artwork by declaring a set. tools/images/dedupe-theme-assets.php moves identical files out of the
 * theme folders into the shared folder (docs/themes.md).
 *
 * The per-theme lookup map (relative path => URL) is cached in var/game/theme-assets-<theme>.php and rebuilt
 * when the modification time of one of the folders it was built from changes (a file added or removed).
 * Static: the resolver needs no request state, only the project paths.
 */
final class ThemeAssets
{
    public const string THEMES_DIR = 'public/styles/theme/';
    public const string SHARED_DIR = 'public/styles/resource/theme-shared/';

    /** @var array<string, array<string, string>> per-process memo: theme => path => URL */
    private static array $maps = [];

    public static function resolve(string $theme, string $path): string
    {
        $path = ltrim(preg_replace('#^\./#', '', $path), '/');
        if (!isset(self::$maps[$theme])) {
            self::$maps[$theme] = self::load($theme);
        }

        return self::$maps[$theme][$path] ?? self::themeUrl($theme) . $path;
    }

    /** the artwork sets a theme declares in style.cfg (list of set names, in lookup order) */
    public static function setsOf(string $theme): array
    {
        $cfg = ProjectPaths::ROOT . self::THEMES_DIR . $theme . '/style.cfg';
        if (!is_file($cfg)) {
            return [];
        }
        $Skin = [];
        require $cfg;
        $sets = $Skin['assetSets'] ?? [];

        return array_values(array_filter((array) $sets, static fn($s) => is_string($s) && preg_match('/^[A-Za-z0-9_-]+$/', $s)));
    }

    /**
     * Lookup chain of a theme: list of [absolute directory, URL prefix] in priority order.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function chain(string $theme): array
    {
        $root = ProjectPaths::ROOT;
        $chain = [[$root . self::THEMES_DIR . $theme . '/', self::themeUrl($theme)]];
        foreach (self::setsOf($theme) as $set) {
            $chain[] = [$root . self::SHARED_DIR . 'sets/' . $set . '/', './styles/resource/theme-shared/sets/' . $set . '/'];
        }
        $chain[] = [$root . self::SHARED_DIR . 'common/', './styles/resource/theme-shared/common/'];

        return $chain;
    }

    public static function themeUrl(string $theme): string
    {
        return './styles/theme/' . $theme . '/';
    }

    /** @return array<string, string> */
    private static function load(string $theme): array
    {
        $file = ProjectPaths::CACHE . 'theme-assets-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $theme) . '.php';
        $cached = is_file($file) ? (@include $file) : null;
        if (is_array($cached) && self::fresh($cached['dirs'] ?? [])) {
            return $cached['map'];
        }

        $dirs = [];
        $map = [];
        foreach (self::chain($theme) as [$dir, $url]) {
            $files = self::scan($dir, '', $dirs);
            foreach ($files as $rel) {
                $map[$rel] ??= $url . $rel;
            }
            // `x.webp` also answers for a requested x.gif / x.jpg / x.png (converted artwork), after the exact files
            foreach ($files as $rel) {
                if (str_ends_with($rel, '.webp')) {
                    foreach (['gif', 'jpg', 'jpeg', 'png'] as $ext) {
                        $map[substr($rel, 0, -4) . $ext] ??= $url . $rel;
                    }
                }
            }
        }
        $dirs[ProjectPaths::ROOT . self::THEMES_DIR . $theme . '/style.cfg'] = (int) @filemtime(ProjectPaths::ROOT . self::THEMES_DIR . $theme . '/style.cfg');

        if (!is_dir(ProjectPaths::CACHE)) {
            @mkdir(ProjectPaths::CACHE, 0775, true);
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, '<?php return ' . var_export(['dirs' => $dirs, 'map' => $map], true) . ';')) {
            @rename($tmp, $file);
        }

        return $map;
    }

    /** @param array<string, int> $dirs directory (or file) => mtime it was built from; a missing folder counts as 0 */
    private static function fresh(array $dirs): bool
    {
        if ($dirs === []) {
            return false;
        }
        foreach ($dirs as $path => $mtime) {
            clearstatcache(false, $path);
            if ((int) @filemtime($path) !== $mtime) {
                return false;
            }
        }

        return true;
    }

    /**
     * Files below $dir as relative paths. WebP copies of static images (`foo.png.webp`, made by
     * app:images:webp and picked by the web server) are no assets of their own.
     *
     * @param array<string, int> $dirs collects every folder with its mtime
     * @return list<string>
     */
    private static function scan(string $dir, string $rel, array &$dirs): array
    {
        $dir = rtrim($dir, '/');
        $entries = is_dir($dir . '/' . $rel) ? scandir($dir . '/' . $rel) : false;
        $dirs[rtrim($dir . '/' . $rel, '/')] = (int) @filemtime($dir . '/' . $rel);
        if ($entries === false) {
            return [];
        }

        $found = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $rel . $entry;
            if (is_dir($dir . '/' . $path)) {
                array_push($found, ...self::scan($dir, $path . '/', $dirs));
            } elseif (!preg_match('/\.(png|jpe?g|gif)\.webp$/i', $entry)) {
                $found[] = $path;
            }
        }

        return $found;
    }
}

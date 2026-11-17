<?php

declare(strict_types=1);

namespace App\Game\Core;

use App\Runtime\ProjectPaths;

/**
 * TrueType/OpenType fonts for server-side image rendering (admin setting `ttf_file`). The choice is limited to the
 * files in public/styles/resource/fonts/ (read on every call, so dropped-in fonts show up in the admin dropdown).
 * resolveTtf() never trusts the stored value: a path outside that folder, a missing file (deleted after it was
 * chosen) or another file type falls back to the bundled font, then to any font found, else null.
 */
final class Fonts
{
    public const DIRECTORY = 'public/styles/resource/fonts/';
    public const DEFAULT_TTF = self::DIRECTORY . 'DroidSansMono.ttf';
    private const EXTENSIONS = ['ttf', 'otf'];

    /**
     * Fonts of the folder for the admin dropdown.
     *
     * @return array<string, string> path relative to the project root => path inside the fonts folder
     */
    public static function ttfOptions(): array
    {
        $directory = ProjectPaths::ROOT . self::DIRECTORY;
        if (!is_dir($directory)) {
            return [];
        }

        $options = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory)));
                $options[self::DIRECTORY . $relative] = $relative;
            }
        }
        ksort($options);

        return $options;
    }

    /** absolute path of the configured font if it is a font inside the fonts folder, else of the fallback; null without any font */
    public static function resolveTtf(?string $configured): ?string
    {
        foreach ([$configured, self::DEFAULT_TTF, array_key_first(self::ttfOptions())] as $candidate) {
            $path = $candidate === null || $candidate === '' ? null : self::insideFontDirectory($candidate);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /** absolute path of an existing font file below the fonts folder (no traversal, no other file types), else null */
    private static function insideFontDirectory(string $relative): ?string
    {
        $directory = realpath(ProjectPaths::ROOT . self::DIRECTORY);
        $path = realpath(ProjectPaths::ROOT . ltrim($relative, '/'));
        if ($directory === false || $path === false || !is_file($path)) {
            return null;
        }
        if (!str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true) ? $path : null;
    }
}

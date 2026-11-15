<?php

declare(strict_types=1);

namespace App\Runtime;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Best-effort file operations without the `@` error operator: existence and permissions are checked first,
 * and a real failure (permissions, full disk, ...) is written to the error log (var/log/game.log in the game)
 * instead of being hidden. Nothing here throws; callers get a bool / null / 0 and decide what a failure means.
 * Static on purpose: it is used from static and path-only code as well (see ProjectPaths).
 */
final class SafeFile
{
    private static ?Filesystem $fs = null;

    private static function fs(): Filesystem
    {
        return self::$fs ??= new Filesystem();
    }

    /** Content of a readable regular file, null when it does not exist or cannot be read. */
    public static function read(string $path, int $maxLength = -1): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $content = $maxLength >= 0 ? file_get_contents($path, false, null, 0, $maxLength) : file_get_contents($path);

        return $content === false ? null : $content;
    }

    /** Modification time of a file or directory, 0 when it does not exist (callers treat that as "stale"). */
    public static function mtime(string $path): int
    {
        if (!file_exists($path)) {
            return 0;
        }
        $mtime = filemtime($path);

        return $mtime === false ? 0 : $mtime;
    }

    /**
     * Writes a file atomically: a private (0600) temporary file in the same directory gets the content and the
     * final permissions, then replaces the target by rename, so secrets are never readable by others, not even
     * briefly, and readers never see a half-written file. Missing parent directories are created.
     *
     * @param int|null $mode permissions of the result; null keeps those of an existing file, else 0666 & ~umask
     * @return bool false (and an error log entry) when the directory is not writable or writing/renaming failed
     */
    public static function write(string $path, string $content, ?int $mode = null): bool
    {
        $dir = dirname($path);
        if (!self::mkdir($dir)) {
            return false;
        }
        if (!is_writable($dir) || (is_file($path) && !is_writable($path))) {
            error_log('SafeFile::write ' . $path . ': not writable');

            return false;
        }
        $tmp = tempnam($dir, '.tmp');
        if ($tmp === false) {
            error_log('SafeFile::write ' . $path . ': cannot create a temporary file');

            return false;
        }
        $mode ??= is_file($path) ? (fileperms($path) & 0777) : (0666 & ~umask());
        if (file_put_contents($tmp, $content) === false || !chmod($tmp, $mode) || !rename($tmp, $path)) {
            error_log('SafeFile::write ' . $path . ': write failed');
            self::remove($tmp);

            return false;
        }

        return true;
    }

    /**
     * Image header information ({@see getimagesizefromstring()}), false when the file is missing, unreadable or no
     * recognised image. Only the first MB is read: size and MIME type are in the header.
     *
     * @return array<int|string, mixed>|false
     */
    public static function imageSize(string $path): array|false
    {
        $head = self::read($path, 1024 * 1024);

        return $head === null || $head === '' ? false : getimagesizefromstring($head);
    }

    /** Creates a directory (and parents); true when it exists afterwards. A failure is logged. */
    public static function mkdir(string $dir, int $mode = 0775): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        try {
            self::fs()->mkdir($dir, $mode);

            return true;
        } catch (IOException $e) {
            error_log('SafeFile::mkdir ' . $dir . ': ' . $e->getMessage());

            return is_dir($dir);
        }
    }

    /** Deletes a file or a whole directory tree. A missing path is fine (nothing to do); a real failure is logged. */
    public static function remove(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        try {
            self::fs()->remove($path);

            return true;
        } catch (IOException $e) {
            error_log('SafeFile::remove ' . $path . ': ' . $e->getMessage());

            return false;
        }
    }

    /** Creates the file or updates its modification time. A failure is logged. */
    public static function touch(string $path): bool
    {
        try {
            self::fs()->touch($path);

            return true;
        } catch (IOException $e) {
            error_log('SafeFile::touch ' . $path . ': ' . $e->getMessage());

            return false;
        }
    }

    /** Sets the permissions of an existing file. A failure is logged. */
    public static function chmod(string $path, int $mode): bool
    {
        if (!file_exists($path)) {
            return false;
        }
        try {
            self::fs()->chmod($path, $mode);

            return true;
        } catch (IOException $e) {
            error_log('SafeFile::chmod ' . $path . ': ' . $e->getMessage());

            return false;
        }
    }
}

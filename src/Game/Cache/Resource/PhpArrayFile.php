<?php

declare(strict_types=1);

namespace App\Game\Cache\Resource;

/**
 * Cache files that hold a PHP array as `<?php return [...];` (var_export), written atomically and read with
 * include, so opcache serves them from memory and nothing is ever unserialize()d.
 *
 * Replaces the former serialize() cache files. A file in the old format (does not start with the PHP open tag) is
 * treated as missing, so the caller rebuilds it once; the rebuild overwrites it in the new format.
 */
final class PhpArrayFile
{
    /**
     * Writes the array atomically (temp file + rename) so a concurrent request never reads a half-written file.
     *
     * @param array<mixed> $data must consist of scalars and arrays only
     * @return bool false when the file could not be written
     */
    public static function write(string $path, array $data): bool
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, '<?php return ' . var_export($data, true) . ';' . "\n") === false) {
            return false;
        }
        if (!rename($tmp, $path)) {
            unlink($tmp);

            return false;
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        return true;
    }

    /**
     * Reads a file written by write().
     *
     * @return array<mixed>|null null when the file is missing, in the legacy serialize() format, or damaged
     */
    public static function read(string $path): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = fread($handle, 6);
        fclose($handle);
        if ($head !== '<?php ') {
            return null;
        }

        try {
            $data = include $path;
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}

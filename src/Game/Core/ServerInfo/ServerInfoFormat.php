<?php

namespace App\Game\Core\ServerInfo;

/** Small formatting and probing helpers of the server information (durations, byte sizes, host and extension checks). */
final class ServerInfoFormat
{
    public static function extensionLoaded(string $name): bool
    {
        return extension_loaded($name) || ($name === 'opcache' && extension_loaded('Zend OPcache'));
    }

    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);

        return $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]'
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.ddev.site')
            || str_ends_with($host, '.test') || str_ends_with($host, '.local');
    }

    public static function duration(int $seconds): string
    {
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($d > 0) {
            return $d . 'd ' . $h . 'h';
        }
        if ($h > 0) {
            return $h . 'h ' . $m . 'm';
        }
        if ($m > 0) {
            return $m . 'm';
        }

        return $seconds . 's';
    }

    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            ++$i;
        }

        return ($i === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$i];
    }
}

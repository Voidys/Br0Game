<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Cache\Resource;

/**
 * File storage of cache entries: one PHP array file `cache.<key>.php` in the cache directory (var/game/, the system temp dir if that is not writable).
 */
class CacheFile
{
    private $path;
    public function __construct()
    {
        $this->path	= is_writable(\App\Runtime\ProjectPaths::CACHE) ? \App\Runtime\ProjectPaths::CACHE : $this->getTempPath();
    }

    private function getTempPath()
    {
        return sys_get_temp_dir();
    }

    /**
     * Stores a cache entry as a PHP array file (see PhpArrayFile).
     *
     * @param array<mixed> $Value
     */
    public function store($Key, array $Value)
    {
        return PhpArrayFile::write($this->path . 'cache.' . $Key . '.php', $Value);
    }

    /**
     * Reads a cache entry.
     *
     * @return array<mixed>|false false when missing or still in the old serialized format (rebuild needed)
     */
    public function open($Key)
    {
        return PhpArrayFile::read($this->path . 'cache.' . $Key . '.php') ?? false;
    }

    /**
     * Deletes the cache file of a key; false when it did not exist.
     */
    public function flush($Key)
    {
        if (!file_exists($this->path . 'cache.' . $Key . '.php')) {
            return false;
        }

        return unlink($this->path . 'cache.' . $Key . '.php');
    }
}

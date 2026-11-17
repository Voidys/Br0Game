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

namespace App\Game\Core;

use App\Game\Cache\Resource\CacheFile;
use App\Game\Cache\Builder\BuildCacheInterface;

/**
 * Cache facade for the game data entries ('vars', 'language'): loads an entry from its cache file, builds it with
 * the registered BuildCacheInterface builder when missing, and keeps it in memory for the request.
 */
class Cache
{
    private CacheFile $cacheResource;
    /** @var array<mixed> */
    private array $cacheBuilder = [];
    /** @var array<mixed> */
    private array $cacheObj = [];

    /** cache keys and their builders (formerly registered at runtime via add()) */
    private const BUILDERS = [
        'vars' => 'VarsBuildCache',
        'language' => 'LanguageBuildCache',
    ];

    /**
     * @param Database|null $db  for builders that read the database (vars); the language cache
     *                           needs none, so Language works before a database config exists
     */
    public function __construct(
        private ?Database $db = null,
        private ?Singleton $state = null,
    ) {
        $this->cacheResource = new CacheFile();
        $this->cacheBuilder = self::BUILDERS;
    }

    /**
     * Registers a builder class (in App\Game\Cache\Builder) for a cache key.
     */
    public function add(string $Key, string $ClassName): void
    {
        $this->cacheBuilder[$Key]	= $ClassName;
    }

    /**
     * Content of a cache entry; built when missing unless $rebuild is false (then [] if absent).
     *
     * @return array<mixed>
     */
    public function getData(string $Key, bool $rebuild = true): array
    {
        if (!isset($this->cacheObj[$Key]) && !$this->load($Key)) {
            if ($rebuild) {
                $this->buildCache($Key);
            } else {
                return [];
            }
        }
        return $this->cacheObj[$Key];
    }

    /**
     * Deletes the cache file of a key and rebuilds it right away.
     */
    public function flush(string $Key): bool
    {
        if (!isset($this->cacheObj[$Key]) && !$this->load($Key)) {
            $this->buildCache($Key);
        }

        $this->cacheResource->flush($Key);
        return $this->buildCache($Key);
    }

    /**
     * Loads the entry from its file into memory; false if the file is missing or in an outdated format.
     */
    public function load(string $Key): bool
    {
        $cacheData	= $this->cacheResource->open($Key);

        if (empty($cacheData)) {
            return false;
        }

        $this->cacheObj[$Key] = $cacheData;
        return true;
    }

    /**
     * Runs the builder of the key, keeps the result in memory and writes the cache file.
     */
    public function buildCache(string $Key): bool
    {
        $className = 'App\\Game\\Cache\\Builder\\' . $this->cacheBuilder[$Key];

        /** @var BuildCacheInterface $cacheBuilder */
        $cacheBuilder	= new $className($this->db, $this->state);
        $cacheData		= $cacheBuilder->buildCache();
        $cacheData		= (array) $cacheData;
        $this->cacheObj[$Key] = $cacheData;
        $this->cacheResource->store($Key, $cacheData);
        return true;
    }
}

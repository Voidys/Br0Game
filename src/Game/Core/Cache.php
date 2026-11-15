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

class Cache
{
    private CacheFile $cacheResource;
    private array $cacheBuilder = [];
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

    public function add(string $Key, string $ClassName): void
    {
        $this->cacheBuilder[$Key]	= $ClassName;
    }

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

    public function flush(string $Key): bool
    {
        if (!isset($this->cacheObj[$Key]) && !$this->load($Key)) {
            $this->buildCache($Key);
        }

        $this->cacheResource->flush($Key);
        return $this->buildCache($Key);
    }

    public function load(string $Key): bool
    {
        $cacheData	= $this->cacheResource->open($Key);

        if (empty($cacheData)) {
            return false;
        }

        $cacheData	= unserialize($cacheData);
        if ($cacheData === false) {
            return false;
        }

        $this->cacheObj[$Key] = $cacheData;
        return true;
    }

    public function buildCache(string $Key): bool
    {
        $className = 'App\\Game\\Cache\\Builder\\' . $this->cacheBuilder[$Key];

        /** @var BuildCacheInterface $cacheBuilder */
        $cacheBuilder	= new $className($this->db, $this->state);
        $cacheData		= $cacheBuilder->buildCache();
        $cacheData		= (array) $cacheData;
        $this->cacheObj[$Key] = $cacheData;
        $cacheData		= serialize($cacheData);
        $this->cacheResource->store($Key, $cacheData);
        return true;
    }
}

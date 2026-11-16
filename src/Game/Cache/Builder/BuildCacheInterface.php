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

namespace App\Game\Cache\Builder;

/**
 * Builder of one cache entry (registered in Core\Cache::BUILDERS by key): buildCache() computes the data that is then stored as a PHP array file.
 */
interface BuildCacheInterface
{
    /**
     * Computes the cache content from the database or the language files.
     * @return array<mixed>
     */
    public function buildCache();
}

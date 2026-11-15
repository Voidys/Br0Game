<?php

declare(strict_types=1);

namespace App\Runtime;

/**
 * Fixed project paths for code without a request context (static helpers, cache builders).
 * Request-dependent facts (time, mode, URLs) live in RuntimeContext.
 */
final class ProjectPaths
{
    /** project root with trailing slash */
    public const string ROOT = __DIR__ . '/../../';

    /** game caches */
    public const string CACHE = self::ROOT . 'var/game/';
}

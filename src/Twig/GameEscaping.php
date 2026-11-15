<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\FileExtensionEscapingStrategy;

/**
 * Output escaping policy of the game templates.
 *
 * The game encodes request input when it reads it (App\Game\Core\HTTP::_GP() applies
 * htmlspecialchars), so names, texts and other stored strings are usually HTML-encoded
 * already. Escaping them again with Twig's "html" strategy would print "&amp;lt;" instead of "<".
 *
 * The templates of the game areas (templates/game, login, account, admin, install) therefore use
 * the "game_html" strategy: htmlspecialchars() without double encoding. Raw text is encoded,
 * text that is already encoded passes through unchanged, so every printed value is safe
 * whichever way it was stored. All other templates (Symfony bundles, profiler) keep Twig's default.
 *
 * Values that are meant to be HTML must be marked as such: translations are trusted
 * (App\Twig\LanguageView returns them as Twig\Markup), code-built markup is passed as
 * Twig\Markup or printed with `|raw`. Helper functions that return markup declare
 * `is_safe => ['all']` (a plain `['html']` would not cover "game_html").
 */
final class GameEscaping
{
    /** escaping strategy name, registered with Twig by EscaperConfigurator */
    public const STRATEGY = 'game_html';

    /** template folders below templates/ that belong to the game areas */
    public const AREAS = ['game', 'login', 'account', 'admin', 'install'];

    /** autoescape_service callback (config/packages/twig.yaml): strategy for a template name */
    public static function guess(string $name): string|false
    {
        $path = str_replace('\\', '/', $name);
        // names as Twig loads them ("game/page.x.html.twig") and full paths (lint:twig, cache warmup)
        $relative = ($pos = strrpos($path, 'templates/')) !== false ? substr($path, $pos + strlen('templates/')) : $path;
        $area = strstr($relative, '/', true);
        if ($area !== false && in_array($area, self::AREAS, true) && str_ends_with($relative, '.html.twig')) {
            return self::STRATEGY;
        }

        return FileExtensionEscapingStrategy::guess($name);
    }

    /**
     * The "game_html" escaper: like Twig's "html", but existing entities are not encoded again.
     * Twig hands custom escapers every non-string value too (null, numbers, booleans): they print
     * as PHP's string cast, so null and false print nothing.
     */
    public static function escape(mixed $value, ?string $charset = 'UTF-8'): string
    {
        return htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, $charset ?: 'UTF-8', false);
    }
}

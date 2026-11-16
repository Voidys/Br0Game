<?php

declare(strict_types=1);

namespace App\Game\Alliance;

use App\Game\Core\Language;
use App\Twig\GameEscaping;
use Twig\Markup;

/**
 * Pure text helpers of the alliance pages: safe HTML from translations and stored texts, link sanitising.
 */
final class AllianceText
{
    /**
     * A translation with HTML (<b>, <br>) filled with values: the arguments are escaped (existing
     * entities stay, Markup arguments are kept), the result is marked as safe HTML for the message/page
     * templates. See Language::formatHtml().
     */
    public static function html(Language $lng, string $key, string|int|Markup ...$arguments): Markup
    {
        return new Markup($lng->formatHtml($key, ...$arguments), 'UTF-8');
    }

    /** a stored multi-line text as safe HTML: escaped (existing entities stay), line breaks as <br> */
    public static function multiline(?string $text): Markup
    {
        return new Markup(nl2br(GameEscaping::escape($text ?? '')), 'UTF-8');
    }

    /**
     * Alliance website/image links are rendered as href/src: only absolute http(s) URLs are kept,
     * everything else (javascript:, data:, relative junk) is stored as an empty string.
     */
    public static function httpUrlOrEmpty(string $url): string
    {
        $url = trim($url);
        // stored values are HTML-encoded: judge the decoded URL, and refuse characters that could leave an attribute
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || preg_match('/[\s"\'<>`\\\\]/u', $decoded) === 1 || filter_var($decoded, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }
}

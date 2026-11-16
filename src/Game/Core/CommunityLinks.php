<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * The admin-configurable community resources (config keys `discord_url`, `wiki_url`, `donate_url`,
 * `userscripts_url`; empty = not offered) and the one mechanism that fills them into texts.
 *
 * Texts (translations, templates/texts/<locale>/*.txt) never contain a hard-wired community address. They use
 * the tokens `{discord_url}`, `{wiki_url}` (base URL, deep links are `{wiki_url}/wiki/En/Page`), `{donate_url}`
 * and `{userscripts_url}`, and fill() resolves them:
 *
 * - URL set: the token is replaced by the URL (HTML-attribute safe, trailing slash removed).
 * - URL empty: an anchor whose href starts with the token is replaced by its link text (the sentence stays
 *   readable), and an optional block `<!--discord_url-->...<!--/discord_url-->` (same for the other keys) is
 *   dropped completely. Blocks are kept (markers removed) while the URL is set. Any left-over token is removed.
 */
final class CommunityLinks
{
    /** config keys of the four links */
    public const KEYS = ['discord_url', 'wiki_url', 'donate_url', 'userscripts_url'];

    /**
     * Fills the community tokens of a text.
     *
     * @param array<string, string> $urls config key => URL (missing keys count as empty)
     */
    public static function fill(string $text, array $urls): string
    {
        // quick exit: nothing to do for the vast majority of strings
        if (!str_contains($text, '_url}') && !str_contains($text, '_url-->')) {
            return $text;
        }

        foreach (self::KEYS as $key) {
            $url = rtrim(trim((string) ($urls[$key] ?? '')), '/');
            $token = '{' . $key . '}';

            if ($url !== '') {
                $text = str_replace(['<!--' . $key . '-->', '<!--/' . $key . '-->'], '', $text);
                $text = str_replace($token, htmlspecialchars($url, ENT_QUOTES, 'UTF-8', false), $text);
                continue;
            }

            $text = (string) preg_replace('#<!--' . $key . '-->.*?<!--/' . $key . '-->#s', '', $text);
            $text = (string) preg_replace('#<a\s[^>]*href="' . preg_quote($token, '#') . '[^"]*"[^>]*>(.*?)</a>#s', '$1', $text);
            $text = str_replace($token, '', $text);
        }

        return $text;
    }

    /**
     * Mail texts name the Discord address in a line `{DISCORD}` that follows an introducing sentence. Without a
     * configured address that sentence and the line are removed (otherwise the mail would announce a link that is
     * not there); with one the text is returned unchanged for the usual `{DISCORD}` replacement.
     */
    public static function prepareMail(string $text, string $discordUrl): string
    {
        if (trim($discordUrl) !== '') {
            return $text;
        }

        return (string) preg_replace('#(?:^|\R)[^\r\n]*\R[ \t]*\{DISCORD\}[^\r\n]*\R?#', '', $text);
    }

    /**
     * Community entries of the game navigation: href by entry (`discord`, `wiki`, `donate`), only entries that are
     * offered. Discord and donate need their URL (donate is hidden while a tutorial step is running); the wiki entry
     * goes through the wiki single sign-on route when SSO is configured, else straight to `wiki_url`, else is hidden.
     *
     * @param array<string, string> $urls config key => URL
     * @return array<string, string>
     */
    public static function navLinks(array $urls, bool $wikiSsoConfigured, bool $tutorialActive): array
    {
        $links = [];
        if (trim((string) ($urls['wiki_url'] ?? '')) !== '' || $wikiSsoConfigured) {
            $links['wiki'] = $wikiSsoConfigured ? 'game?page=wikiLogin' : trim((string) $urls['wiki_url']);
        }
        if (trim((string) ($urls['discord_url'] ?? '')) !== '') {
            $links['discord'] = trim($urls['discord_url']);
        }
        if (trim((string) ($urls['donate_url'] ?? '')) !== '' && !$tutorialActive) {
            $links['donate'] = trim($urls['donate_url']);
        }

        return $links;
    }

    /** true for an empty string or an absolute http(s) URL (the only values the admin may store) */
    public static function isValid(string $url): bool
    {
        return \App\Game\Alliance\AllianceText::httpUrlOrEmpty($url) === trim($url);
    }
}

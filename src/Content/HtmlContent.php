<?php

declare(strict_types=1);

namespace App\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * User/editor HTML from the rich text editor (public/scripts/app/rte.js), cleaned on save and again
 * before output. Two profiles:
 *
 *   rich()   CMS pages and news: paragraphs, h2–h4, lists, quotes, code, links, hr, simple tables
 *   basic()  board posts and comments: paragraphs, bold/italic/strike, lists, quotes, code, links
 *
 * Links get rel="noopener nofollow ugc" and only http(s)/mailto/relative targets; everything else
 * (scripts, styles, event handlers, images, iframes) is dropped.
 */
final class HtmlContent
{
    private const INLINE = ['strong', 'b', 'em', 'i', 'u', 's', 'code', 'br', 'a'];
    private const BLOCK_BASIC = ['p', 'ul', 'ol', 'li', 'blockquote', 'pre'];
    private const BLOCK_RICH = ['h2', 'h3', 'h4', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];

    private ?HtmlSanitizer $rich = null;
    private ?HtmlSanitizer $basic = null;

    public function rich(?string $html): string
    {
        return $this->clean($this->rich ??= $this->build([...self::INLINE, ...self::BLOCK_BASIC, ...self::BLOCK_RICH]), $html);
    }

    public function basic(?string $html): string
    {
        return $this->clean($this->basic ??= $this->build([...self::INLINE, ...self::BLOCK_BASIC]), $html);
    }

    /** plain text (old news, text files) as safe paragraphs */
    public function fromPlainText(?string $text): string
    {
        $text = trim(str_replace("\r\n", "\n", (string) $text));
        if ($text === '') {
            return '';
        }
        $paragraphs = preg_split("/\n{2,}/", $text) ?: [];

        return implode('', array_map(
            static fn(string $p): string => '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>',
            $paragraphs,
        ));
    }

    /** text only, shortened at a word boundary (teasers, meta descriptions) */
    public function excerpt(?string $html, int $length = 200): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h2>', '</h3>'], ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $length * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,.;:-") . '…';
    }

    /** true when the HTML has no visible text (e.g. "<p><br></p>" from an emptied editor) */
    public function isEmpty(?string $html): bool
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{00A0}") === '';
    }

    private function clean(HtmlSanitizer $sanitizer, ?string $html): string
    {
        $html = trim((string) $html);

        return $html === '' ? '' : trim($sanitizer->sanitize($html));
    }

    /** @param list<string> $elements */
    private function build(array $elements): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig())
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'noopener nofollow ugc')
            ->withMaxInputLength(200000);
        foreach (array_unique($elements) as $element) {
            $config = $config->allowElement($element, $element === 'a' ? ['href', 'title'] : ($element === 'th' || $element === 'td' ? ['colspan', 'rowspan'] : []));
        }

        return new HtmlSanitizer($config);
    }
}

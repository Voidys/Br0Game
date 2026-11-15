<?php

declare(strict_types=1);

namespace App\View;

/**
 * Compacts rendered HTML that is stored instead of sent (spy reports are saved as message text).
 *
 * Port of Smarty's outputfilter.trimwhitespace (Smarty 4.5, LGPL-3.0, smarty-php/smarty): strips
 * HTML comments and collapses whitespace between tags, leaving <script>, <textarea>, <pre> and
 * conditional comments untouched.
 */
final class HtmlWhitespace
{
    public static function trim(string $source): string
    {
        $store = [];
        $storeIndex = 0;
        $source = preg_replace('/\015\012|\015|\012/', "\n", $source);

        // conditional comments and knockout comments are kept as they are
        $source = self::protect($source, '#<!--((\[[^\]]+\]>.*?<!\[[^\]]+\])|(\s*/?ko\s+.+))-->#is', $store, $storeIndex);
        $source = preg_replace('#<!--.*?-->#ms', '', $source);
        // so is the content of script, textarea and pre elements
        $source = self::protect($source, '#(<script[^>]*>.*?</script[^>]*>)|(<textarea[^>]*>.*?</textarea[^>]*>)|(<pre[^>]*>.*?</pre[^>]*>)#is', $store, $storeIndex);

        $expressions = [
            '#(:SMARTY@!@|>)\s+(?=@!@SMARTY:|<)#s' => '\1 \2',
            '#(([a-z0-9]\s*=\s*("[^"]*?")|(\'[^\']*?\'))|<[a-z0-9_]+)\s+([a-z/>])#is' => '\1 \5',
            '#^\s+<#Ss' => '<',
            '#>\s+$#Ss' => '>',
        ];
        $source = preg_replace(array_keys($expressions), array_values($expressions), $source);

        // put the protected parts back
        $offset = 0;
        if (preg_match_all('#@!@SMARTY:([0-9]+):SMARTY@!@#is', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $length = strlen($match[0][0]);
                $replace = $store[$match[1][0]];
                $source = substr_replace($source, $replace, $match[0][1] + $offset, $length);
                $offset += strlen($replace) - $length;
            }
        }

        return $source;
    }

    /**
     * Replaces every match of $pattern with a placeholder and remembers the original in $store.
     *
     * @param list<string> $store
     */
    private static function protect(string $source, string $pattern, array &$store, int &$storeIndex): string
    {
        $offset = 0;
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $store[] = $match[0][0];
                $length = strlen($match[0][0]);
                $replace = '@!@SMARTY:' . $storeIndex . ':SMARTY@!@';
                $source = substr_replace($source, $replace, $match[0][1] - $offset, $length);
                $offset += $length - strlen($replace);
                $storeIndex++;
            }
        }

        return $source;
    }
}

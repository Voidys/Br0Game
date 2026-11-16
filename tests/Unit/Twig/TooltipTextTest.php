<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\GameExtension;
use App\Twig\TemplateExtension;
use PHPUnit\Framework\TestCase;

/** Values inside data-tooltip-content are decoded once by the attribute parser and parsed as HTML by tooltip.js. */
final class TooltipTextTest extends TestCase
{
    public function testStoredEntitiesStayTextAfterTheAttributeDecode(): void
    {
        $out = (string) TemplateExtension::tooltipText('&lt;u id=x&gt;L&lt;/u&gt;&#039;');

        self::assertSame('&amp;lt;u id=x&amp;gt;L&amp;lt;/u&amp;gt;&amp;#039;', $out);
        // one decode (what the browser does for the attribute) leaves the entities, not tags
        self::assertSame('&lt;u id=x&gt;L&lt;/u&gt;&#039;', html_entity_decode($out, ENT_QUOTES | ENT_HTML5));
    }

    public function testRawMarkupIsEncodedTwice(): void
    {
        $out = (string) TemplateExtension::tooltipText('<img src=x onerror=1>"\'');

        self::assertStringNotContainsString('<', $out);
        self::assertStringNotContainsString('"', $out);
        self::assertStringNotContainsString('&lt;', str_replace('&amp;lt;', '', $out));
    }

    public function testPlainValuesAndNull(): void
    {
        self::assertSame('Alpha 12', (string) TemplateExtension::tooltipText('Alpha 12'));
        self::assertSame('', (string) TemplateExtension::tooltipText(null));
        self::assertSame('5', (string) TemplateExtension::tooltipText(5));
    }

    public function testJsonForScriptEscapesEverythingThatCouldLeaveTheElement(): void
    {
        $json = GameExtension::jsonForScript(['host' => 'a</script><!--"\'&']);

        foreach (['<', '>', '&', "'"] as $char) {
            self::assertStringNotContainsString($char, $json);
        }
        self::assertSame(['host' => 'a</script><!--"\'&'], json_decode($json, true));
    }
}

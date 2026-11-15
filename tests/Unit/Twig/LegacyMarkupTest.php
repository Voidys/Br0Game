<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\TemplateExtension;
use PHPUnit\Framework\TestCase;

/** Old stored messages carry inline Dialog handlers; the filter turns them into data-action attributes. */
final class LegacyMarkupTest extends TestCase
{
    public function testPlayerCardLinkBecomesDataAction(): void
    {
        $html = "Player <a href=\"#\" onclick=\"return Dialog.Playercard(12, 'bob');\"><b>bob</b></a> left";

        self::assertSame(
            'Player <a href="#" data-action="dialog-playercard" data-id="12"><b>bob</b></a> left',
            (string) TemplateExtension::legacyMarkup($html),
        );
    }

    public function testSingleQuotedAndOtherDialogs(): void
    {
        self::assertSame(
            '<a data-action="dialog-pm" data-id="5" href="#">x</a>',
            (string) TemplateExtension::legacyMarkup("<a onclick='return Dialog.PM(5);' href=\"#\">x</a>"),
        );
    }

    public function testOtherMarkupIsLeftAlone(): void
    {
        $html = '<a href="game?page=galaxy" class="x">[1:2:3]</a>';

        self::assertSame($html, (string) TemplateExtension::legacyMarkup($html));
    }
}

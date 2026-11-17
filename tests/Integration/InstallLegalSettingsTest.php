<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\ContentPages;
use App\Runtime\InstallService;
use App\Tests\Support\GameTestCase;

/**
 * The installer's legal step (hosting region, default or blank rules, community board): validation, storage in the
 * global configuration and the effect on the default content of the privacy and rules pages.
 */
final class InstallLegalSettingsTest extends GameTestCase
{
    private function service(): InstallService
    {
        return self::container()->get(InstallService::class);
    }

    private function stored(string $key): ?string
    {
        $value = $this->db->selectSingle('SELECT `value` FROM %%CONFIG%% WHERE `key` = :key', [':key' => $key], 'value');

        return $value === false || $value === null ? null : (string) $value;
    }

    public function testDefaultsKeepTheFormerBehaviour(): void
    {
        $checked = $this->service()->validateLegalSettings([], 'en');

        $this->assertNull($checked['error']);
        $this->assertSame(['privacy_region' => 'eu', 'rules_default' => 'default', 'board_enabled' => false, 'cookie_consent' => false], $checked['values']);
    }

    public function testRejectsUnknownChoices(): void
    {
        $this->assertNotNull($this->service()->validateLegalSettings(['privacy_region' => 'mars'], 'en')['error']);
        $this->assertNotNull($this->service()->validateLegalSettings(['rules_default' => 'maybe'], 'en')['error']);
    }

    public function testAppliesTheChoices(): void
    {
        $checked = $this->service()->validateLegalSettings(['privacy_region' => 'other', 'rules_default' => 'blank', 'board_enabled' => '1'], 'de');
        $this->assertNull($checked['error']);

        $this->service()->applyLegalSettings($checked['values']);

        $this->assertSame('other', $this->stored('privacy_region'));
        $this->assertSame('0', $this->stored('rules_default'));
        $this->assertSame('1', $this->stored('board_enabled'));
    }

    public function testDefaultPagesFollowTheSettings(): void
    {
        $pages = self::container()->get(ContentPages::class);

        $this->service()->applyLegalSettings(['privacy_region' => 'eu', 'rules_default' => 'default', 'board_enabled' => true]);
        $this->assertStringContainsString('GDPR', $pages->findExact('dataPrivacy', 'en')['content']);
        $this->assertNotSame('', trim($pages->findExact('rules', 'en')['content']));

        $this->service()->applyLegalSettings(['privacy_region' => 'other', 'rules_default' => 'blank', 'board_enabled' => true]);
        $general = $pages->findExact('dataPrivacy', 'en')['content'];
        $this->assertStringNotContainsString('GDPR', $general);
        $this->assertStringContainsString('plain language', $general);
        $this->assertSame('', trim($pages->findExact('rules', 'en')['content']));
    }

    public function testLegalStepRenders(): void
    {
        $this->service()->useLanguage('en');
        $html = self::container()->get('twig')->render('install/ins_legal.html.twig', [
            'currentStep' => 7,
            'lang' => 'en',
            'values' => ['privacy_region' => 'other', 'rules_default' => 'blank', 'board_enabled' => false],
            'bots' => 3,
            'asset_version' => 1,
        ]);

        $this->assertStringContainsString('action="./?mode=install&amp;step=7"', $html);
        $this->assertMatchesRegularExpression('/value="other" checked/', $html);
        $this->assertMatchesRegularExpression('/value="blank" checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="board_enabled" value="1" checked/', $html);
        $this->assertStringContainsString('name="bots" value="3"', $html);
        // licence, requirements, database, tables, game, legal, administrator, done
        $this->assertSame(8, substr_count($html, 'install-steps__dot"'));
    }
}

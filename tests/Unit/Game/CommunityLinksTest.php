<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\Core\CommunityLinks;
use App\Game\Core\Config;
use App\Game\Core\Config\ConfigFieldProcessor;
use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\HTTP;
use App\Game\Core\Language;
use App\Game\Core\Singleton;
use App\Runtime\RuntimeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The admin-configurable community links: validation of the four config fields on save, the token mechanism
 * (CommunityLinks::fill / prepareMail) and the shipped FAQ, tutorial, rules and mail texts rendered with the
 * links set and empty. With empty links no text may mention a pr0game address; with links set the
 * configured URL (and wiki deep links as URL + path) must show up.
 */
final class CommunityLinksTest extends TestCase
{
    private const SET = [
        'discord_url' => 'https://chat.example.org/invite',
        'wiki_url' => 'https://wiki.example.org/',
        'donate_url' => 'https://pay.example.org',
        'userscripts_url' => 'https://scripts.example.org',
    ];

    private const FORBIDDEN = '#discord\.gg|wiki\.pr0game|ko-fi|codeberg\.org/pr0game|\{(discord|wiki|donate|userscripts)_url\}|<!--/?\w+_url-->#';

    /** @return list<array{string, string}> */
    public static function urlInputs(): array
    {
        return [
            ['', ''],
            ['https://wiki.example.org', 'https://wiki.example.org'],
            ['javascript:alert(1)', ''],
            ['/relative/path', ''],
            ['ftp://files.example.org', ''],
            ['not a url', ''],
        ];
    }

    #[DataProvider('urlInputs')]
    public function testConfigFieldsOnlyStoreEmptyOrHttpUrls(string $input, string $expected): void
    {
        $state = new Singleton(new RuntimeContext(dirname(__DIR__, 3)));
        $state->requestInput = array_fill_keys(CommunityLinks::KEYS, $input);
        $schema = [];
        foreach (CommunityLinks::KEYS as $key) {
            $schema[$key] = ['type' => ConfigFieldSchema::TYPE_TEXT, 'transform' => 'string', 'validate' => 'http_url', 'default' => ''];
        }

        $processed = ConfigFieldProcessor::processPostData($schema, new HTTP($state));

        foreach (CommunityLinks::KEYS as $key) {
            self::assertSame($expected, $processed[$key], $key);
        }
        self::assertSame($expected === $input, CommunityLinks::isValid($input));
    }

    public function testSchemaDeclaresTheFourFieldsWithEmptyDefaults(): void
    {
        // the field definitions themselves (not the source text, which moves when the schema is reorganised)
        $fields = (new \App\Game\Core\Config\Schema\BasicCommunityLinksFields())->fields($this->createStub(Language::class))['community_links'];
        foreach (CommunityLinks::KEYS as $key) {
            self::assertArrayHasKey($key, $fields, $key);
            self::assertSame('http_url', $fields[$key]['validate'] ?? null, $key);
            self::assertSame('', $fields[$key]['default'] ?? null, $key);
        }
    }

    public function testFillWithUrlsSetReplacesTokensAndKeepsBlocks(): void
    {
        $text = 'A <a href="{wiki_url}/wiki/En/Mond" target="_blank">Moons</a> B <!--discord_url-->join {discord_url}<!--/discord_url-->';

        $out = CommunityLinks::fill($text, self::SET);

        self::assertSame('A <a href="https://wiki.example.org/wiki/En/Mond" target="_blank">Moons</a> B join https://chat.example.org/invite', $out);
    }

    public function testFillWithEmptyUrlsUnwrapsAnchorsAndDropsBlocks(): void
    {
        $text = "See <a\n href=\"{wiki_url}/wiki/X\">the wiki</a>.<!--discord_url--> Join us: <a href=\"{discord_url}\">{discord_url}</a><!--/discord_url--> Bye {donate_url}";

        self::assertSame('See the wiki. Bye ', CommunityLinks::fill($text, []));
    }

    public function testFillLeavesOtherTextAlone(): void
    {
        self::assertSame('Plain <a href="https://example.org/x">text</a> {game_name}', CommunityLinks::fill('Plain <a href="https://example.org/x">text</a> {game_name}', []));
    }

    public function testNavLinksFollowTheConfiguredUrlsAndTheWikiSso(): void
    {
        $empty = array_fill_keys(CommunityLinks::KEYS, '');

        self::assertSame([], CommunityLinks::navLinks($empty, false, false));
        // wiki: SSO route wins, else the URL itself, else hidden
        self::assertSame('game?page=wikiLogin', CommunityLinks::navLinks(self::SET, true, false)['wiki']);
        self::assertSame('game?page=wikiLogin', CommunityLinks::navLinks($empty, true, false)['wiki']);
        self::assertSame('https://wiki.example.org/', CommunityLinks::navLinks(self::SET, false, false)['wiki']);
        self::assertArrayNotHasKey('wiki', CommunityLinks::navLinks(['discord_url' => 'https://chat.example.org'], false, false));
        // discord and donate need their URL, donate is hidden during the tutorial
        self::assertSame(['wiki', 'discord', 'donate'], array_keys(CommunityLinks::navLinks(self::SET, false, false)));
        self::assertSame(['wiki', 'discord'], array_keys(CommunityLinks::navLinks(self::SET, false, true)));
        self::assertSame(['donate' => 'https://pay.example.org'], CommunityLinks::navLinks(['donate_url' => 'https://pay.example.org'], false, false));
    }

    public function testMailWithoutDiscordLosesTheIntroSentenceAndTheLine(): void
    {
        $mail = "Hello\n\nProblems? Ask on our Discord:\n{DISCORD}\n\nBye\n";

        self::assertSame("Hello\n\nBye\n", CommunityLinks::prepareMail($mail, ''));
        self::assertSame($mail, CommunityLinks::prepareMail($mail, 'https://chat.example.org'));
    }

    public function testEveryShippedMailTextIsCleanWithoutDiscord(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/templates/texts/*/email_*.txt') as $file) {
            $out = CommunityLinks::prepareMail((string) file_get_contents($file), '');
            self::assertStringNotContainsString('{DISCORD}', $out, $file);
            self::assertStringNotContainsString('discord.gg', $out, $file);
        }
    }

    public function testShippedTextsMentionNoPr0gameAddressWithEmptyLinks(): void
    {
        foreach ($this->shippedTexts() as $origin => $text) {
            self::assertDoesNotMatchRegularExpression(self::FORBIDDEN, CommunityLinks::fill($text, []), $origin);
        }
    }

    public function testShippedTextsUseTheConfiguredUrlsWhenSet(): void
    {
        $rendered = implode("\n", array_map(static fn(string $t): string => CommunityLinks::fill($t, self::SET), $this->shippedTexts()));

        self::assertDoesNotMatchRegularExpression(self::FORBIDDEN, $rendered);
        self::assertStringContainsString('href="https://wiki.example.org/wiki/En/Expoanleitung"', $rendered);
        self::assertStringContainsString('https://chat.example.org/invite', $rendered);
    }

    public function testRulesTemplateOfTheLanguageObjectIsFilledFromTheConfig(): void
    {
        $cwd = getcwd();
        chdir(dirname(__DIR__, 3));
        try {
            foreach ([self::SET, array_fill_keys(CommunityLinks::KEYS, '')] as $urls) {
                $registry = $this->createStub(ConfigRegistry::class);
                $registry->method('isLoaded')->willReturn(true);
                $registry->method('get')->willReturn(new Config($urls, $registry));
                $language = new Language($registry, 'en');

                $rules = $language->getTemplate('rules');
                $mail = $language->getTemplate('email_reg_done');

                if ($urls['wiki_url'] === '') {
                    self::assertDoesNotMatchRegularExpression(self::FORBIDDEN, $rules);
                    self::assertStringNotContainsString('{DISCORD}', $mail);
                } else {
                    self::assertStringContainsString('href="https://wiki.example.org/wiki/En/Regeln"', $rules);
                    self::assertStringContainsString('{DISCORD}', $mail);
                }
            }
        } finally {
            chdir($cwd);
        }
    }

    /**
     * All strings of the FAQ, tutorial and ingame translations plus the rules texts, keyed by origin.
     *
     * @return array<string, string>
     */
    private function shippedTexts(): array
    {
        $root = dirname(__DIR__, 3);
        $texts = [];
        $walk = static function (mixed $value, string $path) use (&$walk, &$texts): void {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $walk($item, $path . '.' . $key);
                }
            } elseif (is_string($value)) {
                $texts[$path] = $value;
            }
        };
        foreach (['faq', 'tutorial', 'ingame', 'tech'] as $domain) {
            foreach (glob($root . '/translations/' . $domain . '.*.yaml') as $file) {
                $walk(Yaml::parseFile($file), basename($file));
            }
        }
        foreach (glob($root . '/templates/texts/*/rules.txt') as $file) {
            $texts[$file] = (string) file_get_contents($file);
        }

        return $texts;
    }
}

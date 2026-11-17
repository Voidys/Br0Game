<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Language;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Twig\Markup;

/**
 * Language::format() and friends: the printf helper for translated texts, its placeholder counting,
 * the argument-count check and the HTML escaping rule, plus a scan that keeps every language in step
 * with English for each text that is filled with values.
 */
final class LanguageFormatTest extends TestCase
{
    /** texts whose key is built or chosen at run time, so the static scan cannot see them */
    private const DYNAMIC_KEY_PATTERNS = [
        '/^cff_mission_(own|other|target)_/',
        '/^sys_mess_spy_report_details_/',
        '/^al_users_list_/',
        '/^(fl_attack_confirm_outlaw|fl_spy_confirm_outlaw)$/',
    ];

    private bool $strictBefore;

    protected function setUp(): void
    {
        $this->strictBefore = Language::$strictFormat;
        Language::$strictFormat = true;
    }

    protected function tearDown(): void
    {
        Language::$strictFormat = $this->strictBefore;
    }

    /** @param array<string, mixed> $texts */
    private function language(array $texts): Language
    {
        $language = new Language($this->createStub(ConfigRegistry::class), 'en');
        $language->addData($texts);

        return $language;
    }

    public function testFormatFillsPlaceholders(): void
    {
        $lng = $this->language(['hello' => 'Hello %s, you have %d points (%.1f%%)', 'order' => '%2$s before %1$s']);

        self::assertSame('Hello Bob, you have 5 points (12.5%)', $lng->format('hello', 'Bob', 5, 12.5));
        self::assertSame('b before a', $lng->format('order', 'a', 'b'));
    }

    public function testFormatDoesNotEscapeButFormatHtmlDoes(): void
    {
        $lng = $this->language(['msg' => '<b>%s</b> joined']);

        self::assertSame('<b><i>x</i></b> joined', $lng->format('msg', '<i>x</i>'));
        self::assertSame('<b>&lt;i&gt;x&lt;/i&gt;</b> joined', $lng->formatHtml('msg', '<i>x</i>'));
    }

    public function testFormatHtmlDoesNotDoubleEncodeAndKeepsMarkupAndNumbers(): void
    {
        $lng = $this->language(['msg' => '%s / %s / %d']);

        self::assertSame('a &amp; b / <a href="#">x</a> / 7', $lng->formatHtml('msg', 'a &amp; b', new Markup('<a href="#">x</a>', 'UTF-8'), 7));
        self::assertSame('a &amp; b / x / 7', $lng->formatHtml('msg', 'a & b', 'x', 7));
    }

    public function testFormatInReadsNestedTexts(): void
    {
        $lng = $this->language(['mail' => ['subject' => ['changed' => 'Mail changed on %s']]]);

        self::assertSame('Mail changed on Game', $lng->formatIn(['mail', 'subject', 'changed'], 'Game'));
        self::assertSame('mail.subject.missing', $lng->formatIn(['mail', 'subject', 'missing']));
    }

    public function testMissingKeyStaysTheKey(): void
    {
        self::assertSame('no_such_key', $this->language([])->format('no_such_key', 'x'));
    }

    public function testPlaceholderCounting(): void
    {
        self::assertSame(0, Language::placeholderCount('plain text'));
        self::assertSame(0, Language::placeholderCount('100%% sure'));
        self::assertSame(2, Language::placeholderCount('%s of %d'));
        self::assertSame(2, Language::placeholderCount('%2$s then %1$s'));
        self::assertSame(3, Language::placeholderCount('%1$s %3$s'));
        self::assertSame(1, Language::placeholderCount('%.2f%% done'));
        self::assertSame(1, Language::placeholderCount('%05d'));
        self::assertSame([1 => 's', 2 => 'd'], Language::placeholders('%s %d %%'));
        self::assertSame([1 => 's', 2 => 's'], Language::placeholders('%2$s %1$s'));
    }

    public function testMismatchThrowsInStrictMode(): void
    {
        $lng = $this->language(['two' => '%s and %s']);

        $this->expectException(\LogicException::class);
        $lng->format('two', 'only one');
    }

    public function testTooManyArgumentsThrowInStrictMode(): void
    {
        $lng = $this->language(['one' => 'value %s']);

        $this->expectException(\LogicException::class);
        $lng->format('one', 'a', 'b');
    }

    public function testMismatchIsLoggedAndRepairedInProduction(): void
    {
        Language::$strictFormat = false;
        $lng = $this->language(['two' => '%s and %s']);
        $log = tempnam(sys_get_temp_dir(), 'fmt');
        $before = ini_set('error_log', $log);

        try {
            self::assertSame('a and ', $lng->format('two', 'a'));
            self::assertSame('a and b', $lng->format('two', 'a', 'b', 'c'));
        } finally {
            ini_set('error_log', (string) $before);
        }
        self::assertStringContainsString('has 2 placeholder(s) but got 1 argument(s)', (string) file_get_contents($log));
        unlink($log);
    }

    /**
     * Every text that is filled with values needs the same arguments in every language: a translation
     * with another placeholder count would fail (or print garbage) only for the players of that language.
     */
    public function testEveryLanguageHasTheSamePlaceholdersAsEnglish(): void
    {
        $root = dirname(__DIR__, 3);
        $locales = array_keys(Yaml::parseFile($root . '/config/languages.yaml'));
        $texts = [];
        foreach ($locales as $locale) {
            $texts[$locale] = $this->loadLocale($root, $locale);
        }
        $english = $texts['en'];
        $keys = $this->usedKeys($root, array_keys($english));
        self::assertGreaterThan(80, count($keys), 'the scan should find the formatted texts');

        $problems = [];
        foreach ($keys as $key) {
            if (!isset($english[$key]) || !is_string($english[$key])) {
                continue;
            }
            $expected = Language::placeholders($english[$key]);
            foreach ($locales as $locale) {
                $text = $texts[$locale][$key] ?? null;
                if (!is_string($text)) {
                    continue; // missing: the game falls back to English
                }
                $found = Language::placeholders($text);
                // the same number of arguments; a text may skip one (%1$s ... %4$s) but not use another
                if ($found !== [] ? (Language::placeholderCount($text) !== Language::placeholderCount($english[$key]) || array_diff_key($found, $expected) !== []) : $expected !== []) {
                    $problems[] = sprintf('%s [%s]: has %d placeholder(s), en has %d', $key, $locale, Language::placeholderCount($text), Language::placeholderCount($english[$key]));
                }
            }
        }

        self::assertSame([], $problems, "placeholder mismatches:\n" . implode("\n", $problems));
    }

    /**
     * the keys the code fills with values: literals in format()/formatHtml()/LNG.format() calls
     *
     * @param array<mixed> $knownKeys
     * @return array<mixed>
     */
    private function usedKeys(string $root, array $knownKeys): array
    {
        $known = array_flip($knownKeys);
        $keys = [];
        $sources = [];
        foreach (['src' => '/\.php$/', 'templates' => '/\.twig$/'] as $dir => $pattern) {
            $files = new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)), $pattern);
            foreach ($files as $file) {
                $sources[] = (string) file_get_contents($file->getPathname());
            }
        }
        foreach ($sources as $code) {
            preg_match_all('/(?:->format(?:Html)?\(|LNG\.format\(|AllianceText::html\([^,]+,)([^,)]*)/', $code, $calls);
            foreach ($calls[1] as $firstArgument) {
                preg_match_all('/[\'"](\w+)[\'"]/', $firstArgument, $literals);
                foreach ($literals[1] as $literal) {
                    if (isset($known[$literal])) {
                        $keys[$literal] = true;
                    }
                }
            }
            // formatIn(['a', 'b']) lists are nested texts: not compared here
        }
        foreach ($knownKeys as $key) {
            foreach (self::DYNAMIC_KEY_PATTERNS as $pattern) {
                if (preg_match($pattern, (string) $key)) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * all top-level texts of one language (every domain merged, as the game does)
     *
     * @return array<mixed>
     */
    private function loadLocale(string $root, string $locale): array
    {
        $texts = [];
        foreach (glob($root . '/translations/*.' . $locale . '.yaml') ?: [] as $file) {
            $texts = array_replace($texts, Yaml::parseFile($file) ?? []);
        }

        return $texts;
    }
}

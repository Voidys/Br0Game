<?php

namespace App\Twig;

use App\Game\Core\Singleton;
use App\Game\Core\ThemeAssets;
use App\Game\Core\ViewHelpers;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Functions, filters and globals of the game area templates (templates/{game,login,account,admin,install}/).
 *
 * - `LNG`: the language strings of the current request (App\Twig\LanguageView, trusted markup).
 * - Game view helpers (pretty_number, shortly_number, allowedTo, isModuleAvailable) and a small set
 *   of PHP functions with PHP semantics (empty, isset, count, in_array, ...), as the templates
 *   were written against them.
 * - Markup helpers (icon, html_options) and the escape-aware `format`/`sprintf`/`nl2br`. Their output
 *   is safe for every escaping strategy (`is_safe => ['all']`), see App\Twig\GameEscaping.
 */
final class TemplateExtension extends AbstractExtension implements GlobalsInterface
{
    /** PHP functions available under their own name, as function and as filter. */
    private const PASSTHROUGH = [
        'pretty_fly_time', 'getRestTimeFormat', 'array_key_exists', 'array_filter', 'array_sum', 'intval', 'count',
        'in_array', 'is_array', 'is_numeric', 'strip_tags', 'ceil', 'floor',
    ];

    public function __construct(
        private readonly ViewHelpers $viewHelpers,
        private readonly Singleton $state,
    ) {}

    /** @return array{LNG: LanguageView} the translation view of the current request */
    public function getGlobals(): array
    {
        return ['LNG' => LanguageView::of($this->state)];
    }

    /** URL of a theme image (own folder, artwork sets, common), see App\Game\Core\ThemeAssets */
    public function themeAsset(string $path, ?string $theme = null): string
    {
        $theme ??= isset($this->state->THEME) ? $this->state->THEME->getImageThemeName() : DEFAULT_THEME;

        return ThemeAssets::resolve($theme, $path);
    }

    /** @return array<string, callable> game view helpers, as function and as filter */
    private function viewHelperCallables(): array
    {
        return [
            'pretty_number' => $this->viewHelpers->prettyNumber(...),
            'shortly_number' => $this->viewHelpers->shortlyNumber(...),
            'allowedTo' => $this->viewHelpers->allowedTo(...),
            'isModuleAvailable' => $this->viewHelpers->isModuleAvailable(...),
        ];
    }

    /** @return list<TwigFunction> PHP passthroughs, game view helpers (pretty_number, allowedTo, ...), theme_asset, html_options, icon, sprintf and small PHP-semantics helpers kept from the Smarty templates */
    public function getFunctions(): array
    {
        $functions = [];
        foreach (self::PASSTHROUGH as $name) {
            $functions[] = new TwigFunction($name, self::call($name));
        }
        foreach ($this->viewHelperCallables() as $name => $callable) {
            $functions[] = new TwigFunction($name, $callable);
        }
        $functions[] = new TwigFunction('theme_asset', $this->themeAsset(...));
        $functions[] = new TwigFunction('round', static fn($v, int $precision = 0) => round((float) $v, $precision));
        $functions[] = new TwigFunction('json_encode', 'json_encode');
        $functions[] = new TwigFunction('php_date', 'date');
        $functions[] = new TwigFunction('php_number_format', [self::class, 'numberFormat']);
        $functions[] = new TwigFunction('php_array', static fn(...$items) => $items);
        $functions[] = new TwigFunction('php_const', static fn(string $name) => defined($name) ? constant($name) : null);
        $functions[] = new TwigFunction('empty', [self::class, 'isEmpty']);
        $functions[] = new TwigFunction('isset', static fn(...$v) => !in_array(null, $v, true));
        $functions[] = new TwigFunction('icon', [self::class, 'icon'], ['is_safe' => ['all']]);
        $functions[] = new TwigFunction('sprintf', [self::class, 'format'], ['is_safe' => ['all']]);

        return $functions;
    }

    /** @return list<TwigFilter> the same passthroughs and view helpers as filters, plus escape-aware format/sprintf/nl2br, legacy_markup, php_date, count_characters, ... */
    public function getFilters(): array
    {
        $filters = [];
        foreach (self::PASSTHROUGH as $name) {
            $filters[] = new TwigFilter($name, self::call($name));
        }
        foreach ($this->viewHelperCallables() as $name => $callable) {
            $filters[] = new TwigFilter($name, $callable);
        }
        // escape-aware replacements of Twig's own format/nl2br, see format() and nl2br()
        $filters[] = new TwigFilter('format', [self::class, 'format'], ['is_safe' => ['all']]);
        $filters[] = new TwigFilter('sprintf', [self::class, 'format'], ['is_safe' => ['all']]);
        $filters[] = new TwigFilter('nl2br', [self::class, 'nl2br'], ['is_safe' => ['all']]);
        $filters[] = new TwigFilter('tooltip_text', [self::class, 'tooltipText'], ['is_safe' => ['all']]);
        $filters[] = new TwigFilter('legacy_markup', [self::class, 'legacyMarkup'], ['is_safe' => ['all']]);
        $filters[] = new TwigFilter('php_date', 'date');
        $filters[] = new TwigFilter('php_number_format', [self::class, 'numberFormat']);
        // {foreach} semantics: null -> nothing, iterable -> as is, anything else -> one element
        $filters[] = new TwigFilter('min', 'min');
        $filters[] = new TwigFilter('max', 'max');
        $filters[] = new TwigFilter('range', 'range');
        $filters[] = new TwigFilter('count_characters', static function ($v, bool $includeSpaces = false) {
            return $includeSpaces
                ? mb_strlen((string) $v)
                : preg_match_all('/[^\s]/u', (string) $v);
        });

        return $filters;
    }

    /**
     * sprintf() that keeps markup intact: a trusted format string (a translation, Twig\Markup) keeps
     * its HTML, a plain one is escaped; the arguments are escaped unless they are Markup themselves.
     */
    public static function format(mixed $format, mixed ...$args): Markup
    {
        $escape = static fn(mixed $v): mixed => match (true) {
            $v instanceof Markup => (string) $v,
            is_string($v), $v instanceof \Stringable => GameEscaping::escape((string) $v, 'UTF-8'),
            default => $v,
        };

        return new Markup(sprintf((string) $escape($format), ...array_map($escape, $args)), 'UTF-8');
    }

    /**
     * Stored message HTML written before the Content-Security-Policy carries inline handlers such as
     * onclick="return Dialog.Playercard(12, 'name');" (blocked by the policy, and reported). Rewrites those
     * player/info links to the data attributes of public/scripts/app/actions.js before the browser sees them.
     * Use on stored HTML only (`Message.text|legacy_markup`); new texts are written with data attributes.
     */
    public static function legacyMarkup(mixed $html): Markup
    {
        $out = preg_replace_callback(
            '/\sonclick\s*=\s*(["\'])\s*(?:return\s+)?Dialog\.(Playercard|PM|Buddy|info|ach)\(\s*(\d+)(?:(?!\1).)*\1/is',
            static fn(array $m): string => ' data-action="dialog-' . strtolower($m[2]) . '" data-id="' . $m[3] . '"',
            (string) $html,
        );

        return new Markup($out ?? (string) $html, 'UTF-8');
    }

    /**
     * A plain value (player name, leader title, link) for use INSIDE the HTML of a `data-tooltip-content` attribute.
     * The browser decodes the attribute once and tooltip.js then parses the result as HTML, so the value is encoded
     * twice: stored `&lt;u&gt;` (or raw `<u>`) ends up as the text "<u>" and never as an element. Always use it for
     * non-translation values in tooltip markup, see docs/templates.md.
     */
    public static function tooltipText(mixed $value): Markup
    {
        return new Markup(htmlspecialchars(GameEscaping::escape((string) $value, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true), 'UTF-8');
    }

    /** nl2br() for plain text: escapes it (unless it is Markup), then turns line breaks into <br> */
    public static function nl2br(mixed $text): Markup
    {
        $html = $text instanceof Markup ? (string) $text : GameEscaping::escape((string) $text, 'UTF-8');

        return new Markup(nl2br($html), 'UTF-8');
    }

    /** PHP's empty(), also for Countable objects (Markup, LanguageView lists) */
    public static function isEmpty(mixed $value = null): bool
    {
        return $value instanceof \Countable ? count($value) === 0 : empty($value);
    }

    /**
     * Late-bound call: the game's global helpers (pretty_number, ...) are only defined once
     * the game code is bootstrapped, not when templates are compiled or linted.
     */
    private static function call(string $function): \Closure
    {
        return static fn(...$args) => $function(...$args);
    }

    /**
     * A Lucide icon from the local sprite (public/styles/resource/icons/lucide.svg). Decorative
     * unless a label is given, which becomes the accessible name.
     */
    public static function icon(string $name, ?string $label = null, string $class = ''): string
    {
        $a11y = $label === null || $label === ''
            ? ' aria-hidden="true"'
            : ' role="img" aria-label="' . self::escape($label) . '"';

        return '<svg class="icon' . ($class !== '' ? ' ' . self::escape($class) : '') . '"' . $a11y . '>'
            . '<use href="./styles/resource/icons/lucide.svg?v=' . self::spriteVersion() . '#i-' . self::escape($name) . '"></use></svg>';
    }

    /** cache buster of the icon sprite (its modification time), so new icons show up without a hard reload */
    private static function spriteVersion(): string
    {
        static $version = null;

        return $version ??= (string) \App\Runtime\SafeFile::mtime(\App\Runtime\ProjectPaths::ROOT . 'public/styles/resource/icons/lucide.svg');
    }

    /** PHP number_format() for templates: `$num` is cast to float, separators default to "." and ",". */
    public static function numberFormat($num, int $decimals = 0, ?string $decimalSeparator = '.', ?string $thousandsSeparator = ','): string
    {
        return number_format((float) $num, $decimals, $decimalSeparator, $thousandsSeparator);
    }

    /** Attribute/text escaping without double encoding (input is already entity-encoded by HTTP::_GP). */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_COMPAT, 'UTF-8', false);
    }
}

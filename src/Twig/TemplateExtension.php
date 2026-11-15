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
        $functions[] = new TwigFunction('html_options', [self::class, 'htmlOptions'], ['is_safe' => ['all']]);
        $functions[] = new TwigFunction('icon', [self::class, 'icon'], ['is_safe' => ['all']]);
        $functions[] = new TwigFunction('sprintf', [self::class, 'format'], ['is_safe' => ['all']]);

        return $functions;
    }

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
        $filters[] = new TwigFilter('legacy_markup', [self::class, 'legacyMarkup'], ['is_safe' => ['all']]);
        $filters[] = new TwigFilter('php_date', 'date');
        $filters[] = new TwigFilter('php_number_format', [self::class, 'numberFormat']);
        // {foreach} semantics: null -> nothing, iterable -> as is, anything else -> one element
        $filters[] = new TwigFilter('php_iter', static fn($v) => $v === null ? [] : (is_iterable($v) ? $v : (is_object($v) ? get_object_vars($v) : [$v])));
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
            static fn (array $m): string => ' data-action="dialog-' . strtolower($m[2]) . '" data-id="' . $m[3] . '"',
            (string) $html,
        );

        return new Markup($out ?? (string) $html, 'UTF-8');
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

    public static function numberFormat($num, int $decimals = 0, ?string $decimalSeparator = '.', ?string $thousandsSeparator = ','): string
    {
        return number_format((float) $num, $decimals, $decimalSeparator, $thousandsSeparator);
    }

    /**
     * Port of Smarty's {html_options} (same markup).
     *
     * @param array<string, mixed> $params
     */
    public static function htmlOptions(array $params): string
    {
        $name = $values = $options = $selected = $output = $id = $class = null;
        $extra = '';
        foreach ($params as $key => $value) {
            switch ($key) {
                case 'name':
                case 'class':
                case 'id':
                    $$key = (string) $value;
                    break;
                case 'options':
                    $options = (array) $value;
                    break;
                case 'values':
                case 'output':
                    $$key = array_values((array) $value);
                    break;
                case 'selected':
                    if (is_array($value)) {
                        $selected = [];
                        foreach ($value as $item) {
                            $selected[self::escape((string) $item)] = true;
                        }
                    } else {
                        $selected = self::escape((string) $value);
                    }
                    break;
                case 'strict':
                    break;
                default:
                    if (!is_array($value)) {
                        $extra .= ' ' . $key . '="' . self::escape((string) $value) . '"';
                    }
            }
        }

        if ($options === null && $values === null) {
            return '';
        }

        $html = '';
        $idx = 0;
        if ($options !== null) {
            foreach ($options as $key => $value) {
                $html .= self::optionOutput($key, $value, $selected, $id, $class, $idx);
            }
        } else {
            foreach ($values as $i => $key) {
                $html .= self::optionOutput($key, $output[$i] ?? '', $selected, $id, $class, $idx);
            }
        }

        if (!empty($name)) {
            $htmlClass = !empty($class) ? ' class="' . $class . '"' : '';
            $htmlId = !empty($id) ? ' id="' . $id . '"' : '';
            $html = '<select name="' . $name . '"' . $htmlClass . $htmlId . $extra . '>' . "\n" . $html . '</select>' . "\n";
        }

        return $html;
    }

    private static function optionOutput($key, $value, $selected, ?string $id, ?string $class, int &$idx): string
    {
        if (is_array($value)) {
            $groupIdx = 0;
            $html = '<optgroup label="' . self::escape((string) $key) . '">' . "\n";
            foreach ($value as $k => $v) {
                $html .= self::optionOutput($k, $v, $selected, !empty($id) ? ($id . '-' . $idx) : null, $class, $groupIdx);
            }
            $idx++;

            return $html . "</optgroup>\n";
        }

        $escapedKey = self::escape((string) $key);
        $html = '<option value="' . $escapedKey . '"';
        if (is_array($selected) ? isset($selected[$escapedKey]) : $escapedKey === $selected) {
            $html .= ' selected="selected"';
        }
        $html .= !empty($class) ? ' class="' . $class . ' option"' : '';
        $html .= !empty($id) ? ' id="' . $id . '-' . $idx . '"' : '';
        $html .= '>' . self::escape((string) $value) . '</option>' . "\n";
        $idx++;

        return $html;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_COMPAT, 'UTF-8', false);
    }
}

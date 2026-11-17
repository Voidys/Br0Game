<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Tooltip content that is shipped once as inert `<template>` markup instead of an escaped HTML attribute per trigger:
 *
 *   <a class="tooltip" {{ tooltip(include('game/tooltip.x.html.twig', {...})) }}>   -> data-tooltip-ref="<hash>"
 *   ...
 *   {{ tooltip_templates() }}                                                       -> <template id="tt-<hash>">...</template> for every ref so far
 *
 * The reference is a hash of the content, so triggers with identical tooltips share one template, and the markup is not
 * entity-inflated by attribute escaping. `tooltip.js` reads the template of `data-tooltip-ref` unless the trigger has its own
 * `data-tooltip-content`, and runs the content through the same allowlist sanitizer either way. The content is trusted
 * template output (Twig has escaped every value in it); it is whitespace-collapsed between tags only.
 */
final class TooltipExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, string> hash => tooltip HTML that has no template in the output yet */
    private array $pending = [];

    /** @return list<TwigFunction> tooltip(html), tooltip_templates() */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('tooltip', $this->reference(...), ['is_safe' => ['all']]),
            new TwigFunction('tooltip_templates', $this->templates(...), ['is_safe' => ['all']]),
        ];
    }

    /** The trigger attribute for the tooltip HTML: a template reference, or the attribute itself when the HTML cannot live in a template. */
    public function reference(string|Markup $html): string
    {
        $html = $this->compact((string) $html);
        if (stripos($html, '<template') !== false) {
            return 'data-tooltip-content="' . htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE) . '"';
        }
        $hash = substr(hash('xxh3', $html), 0, 12);
        $this->pending[$hash] = $html;

        return 'data-tooltip-ref="' . $hash . '"';
    }

    /**
     * Whitespace runs become one space (the browser collapses them anyway; tooltips hold no <pre>) and the space between table
     * structure tags goes, which only ever matters as an empty text node there.
     */
    private function compact(string $html): string
    {
        $html = preg_replace('/\s+/', ' ', trim($html)) ?? $html;

        return preg_replace('~(</?(?:table|tbody|thead|tr|td|th|br)\b[^>]*>) (?=<)~i', '$1', $html) ?? $html;
    }

    /** The templates of all tooltips referenced since the last call (each hash once). */
    public function templates(): string
    {
        $out = '';
        foreach ($this->pending as $hash => $html) {
            $out .= '<template id="tt-' . $hash . '">' . $html . '</template>';
        }
        $this->pending = [];

        return $out;
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}

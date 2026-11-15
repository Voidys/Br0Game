<?php

declare(strict_types=1);

namespace App\Twig;

use App\Game\Core\Language;
use App\Game\Core\Singleton;
use Twig\Markup;

/**
 * The `LNG` template global: read-only view of the game's language strings.
 *
 * - Translations are trusted content and may contain markup, so strings come back as
 *   Twig\Markup and are printed unescaped (`{{ LNG.key }}`). Lists (`LNG.tech[id]`) come back as
 *   nested LanguageViews, so their strings are marked the same way.
 * - Every key "exists": a missing key prints the key name (Language::offsetGet()), which keeps
 *   untranslated texts visible. Use `LNG.has('key')` for a real existence check.
 * - Bound lazily to the game state, so it always reflects the language of the current request.
 *
 * Strings combined with other text (`LNG.x ~ y`, filters such as `|upper`) lose the mark and are
 * escaped; `format`/`sprintf` keep it (see TemplateExtension::format()).
 */
final class LanguageView implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    /**
     * @param array<array-key, mixed>|null $values a sub list of the language data; null = all of it
     */
    private function __construct(
        private readonly ?Singleton $state,
        private readonly ?array $values,
    ) {}

    /** the root view over the language of the current request */
    public static function of(Singleton $state): self
    {
        return new self($state, null);
    }

    /** the language object, or null before the game is bootstrapped */
    public function language(): ?Language
    {
        $language = $this->state?->LNG ?? null;

        return $language instanceof Language ? $language : null;
    }

    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    /** real existence check (`LNG.has('key')`, `isset(LNG[key])` in the former templates) */
    public function has(mixed $offset): bool
    {
        return $this->values !== null ? isset($this->values[$offset]) : isset($this->language()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if ($this->values !== null) {
            // inside a list a missing entry is null (only top-level keys fall back to the key name)
            return self::wrap($this->values[$offset] ?? null);
        }
        $language = $this->language();

        return self::wrap($language !== null ? $language[$offset] : $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Templates cannot change language strings.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Templates cannot change language strings.');
    }

    public function getIterator(): \Generator
    {
        foreach ($this->values ?? [] as $key => $value) {
            yield $key => self::wrap($value);
        }
    }

    public function count(): int
    {
        return count($this->values ?? []);
    }

    /** the plain values (for PHP functions that need a real array, e.g. `LNG.months.toArray`) */
    public function toArray(): array
    {
        return $this->values ?? [];
    }

    /** `LNG.months|json_encode` encodes the plain list */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** language methods, e.g. `LNG.getLanguage()` */
    public function __call(string $name, array $arguments): mixed
    {
        $language = $this->language() ?? throw new \LogicException('No language loaded.');

        return $language->$name(...$arguments);
    }

    private static function wrap(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => new Markup($value, 'UTF-8'),
            is_array($value) => new self(null, $value),
            default => $value,
        };
    }
}

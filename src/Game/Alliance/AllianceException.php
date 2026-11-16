<?php

declare(strict_types=1);

namespace App\Game\Alliance;

use Twig\Markup;

/**
 * Early exit of an alliance action, thrown by the alliance services and translated by AllianceController into the
 * matching HTTP ending (the services must not end the request themselves): back to the alliance home, a redirect,
 * a message page with one button, or a JSON answer.
 */
final class AllianceException extends \RuntimeException
{
    public const HOME = 'home';
    public const REDIRECT = 'redirect';
    public const MESSAGE = 'message';
    public const JSON = 'json';

    /**
     * @param array<array{label: string, url: string}> $buttons
     */
    private function __construct(
        public readonly string $kind,
        public readonly string|Markup|array|null $payload = null,
        public readonly array $buttons = [],
    ) {
        parent::__construct($kind);
    }

    /** Redirect to `game?page=alliance` (missing rights, invalid target, ...). */
    public static function home(): self
    {
        return new self(self::HOME);
    }

    /**
     * Redirect to a URL relative to the game root.
     */
    public static function redirect(string $url): self
    {
        return new self(self::REDIRECT, $url);
    }

    /** Message page with a single button (label is the already translated text). */
    public static function message(string|Markup $text, string $label, string $url): self
    {
        return new self(self::MESSAGE, $text, [['label' => $label, 'url' => $url]]);
    }

    /** JSON answer of the ajax dialogs (circular, diplomacy create). */
    public static function json(array $data): self
    {
        return new self(self::JSON, $data);
    }
}

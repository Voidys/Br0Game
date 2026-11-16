<?php

namespace App\Game\Core;

/**
 * The game state of a request or console run, a shared container service: a bag of named values ($state->USER,
 * PLANET, LNG, resource, reslist, pricelist, request data, session, ...) read and written as properties, plus the
 * read-only RuntimeContext ($state->runtime: time, mode, paths, URLs). Values are stored by reference so they can be written in place
 * (`$this->state->USER['key'] = ...`); no local reference aliases.
 *
 * Typed for static analysis (PHPStan reads these; at runtime the bag stays dynamic):
 *
 * @property array<string, mixed> $USER the logged-in player's users row (+ settings), [] when nobody is logged in
 * @property array<string, mixed> $PLANET the current planet's row
 * @property \App\Game\Core\Language $LNG the active language
 * @property \App\Game\Core\Theme $THEME the player's theme
 * @property array<int, string> $resource element id => database column (former vars.php)
 * @property array<string, mixed> $reslist element id lists by group (build, tech, fleet, defense, ..., nested by type)
 * @property array<int, array<string, mixed>> $pricelist element id => cost, factor, consumption, speed, ...
 */
class Singleton
{
    private array $data = [];

    /** shared container service: one game state per request / console run */
    public function __construct(
        /** request time, mode, paths and URLs of this request (the former runtime constants) */
        public readonly \App\Runtime\RuntimeContext $runtime,
    ) {
    }

    /**
     * Returns the stored value by reference (null for unknown names).
     */
    public function &__get(string $name)
    {
        return $this->data[$name];
    }

    /**
     * Stores a value under the name.
     */
    public function __set(string $name, $value): void
    {
        $this->data[$name] = &$value;
    }

    /**
     * True if a non-null value is stored under the name.
     */
    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }
}

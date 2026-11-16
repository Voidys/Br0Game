<?php

namespace App\Game\Core;

/**
 * The game state of a request or console run, a shared container service: a bag of named values ($state->USER,
 * PLANET, LNG, resource, reslist, pricelist, request data, session, ...) read and written as properties, plus the
 * read-only RuntimeContext ($state->runtime: time, mode, paths, URLs). Values are stored by reference so aliases
 * such as `$USER = & $this->state->USER` see later changes.
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

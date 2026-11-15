<?php

namespace App\Game\Core;

class Singleton
{
    private array $data = [];

    /** shared container service: one game state per request / console run */
    public function __construct(
        /** request time, mode, paths and URLs of this request (the former runtime constants) */
        public readonly \App\Runtime\RuntimeContext $runtime,
    ) {
    }

    public function &__get(string $name)
    {
        return $this->data[$name];
    }

    public function __set(string $name, $value): void
    {
        $this->data[$name] = &$value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }
}

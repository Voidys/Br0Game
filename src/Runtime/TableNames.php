<?php

namespace App\Runtime;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves legacy table names (e.g. "accounts" -> "uni1_accounts").
 */
final class TableNames
{
    public function __construct(
        #[Autowire(param: 'app.table_prefix')]
        private readonly string $prefix,
    ) {}

    /**
     * Prefixed table name; only [a-z0-9_] is accepted so the result is safe to embed in SQL.
     *
     * @throws \InvalidArgumentException for any other characters
     */
    public function get(string $table): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException(sprintf('Invalid table name "%s".', $table));
        }

        return $this->prefix . $table;
    }
}

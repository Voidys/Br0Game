<?php

declare(strict_types=1);

namespace App\Game\Model;

use App\Game\Core\Singleton;

/**
 * Builds the typed `User` and `Planet` snapshots from the state arrays, using the element id => column name table
 * of the current game data (`$state->resource`). Obtained through `GameUtilities::models()`.
 *
 * Every call creates a fresh snapshot of the array passed in; nothing is cached, so changes to the arrays are
 * visible to the next call.
 */
final class ModelFactory
{
    public function __construct(
        private readonly Singleton $state,
    ) {}

    /**
     * @param array<string, mixed> $user player row / `$USER` state array
     */
    public function user(array $user): User
    {
        return User::fromState($user, $this->state->resource);
    }

    /**
     * @param array<string, mixed> $planet planet row / `$PLANET` state array
     */
    public function planet(array $planet): Planet
    {
        return Planet::fromState($planet, $this->state->resource);
    }
}

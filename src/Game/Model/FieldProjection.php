<?php

declare(strict_types=1);

namespace App\Game\Model;

/**
 * Outcome of replaying a building queue against the field limit of a planet (BuildFunctions::queueFieldProjectionFor()):
 * occupied and maximum fields after the queue, and whether every queued entry found a free field when its turn came.
 * Immutable.
 */
final readonly class FieldProjection
{
    /**
     * @param int  $current fields occupied after the (valid part of the) queue
     * @param int  $max     field limit after the queue, with the terraformer and moon base levels it reaches
     * @param bool $valid   false when an entry had no free field at its turn (the replay stops there)
     */
    public function __construct(
        public int $current,
        public int $max,
        public bool $valid,
    ) {}

    /** Whether another building fits after the queue: the queue was valid and a field is still free. */
    public function hasFreeField(): bool
    {
        return $this->valid && $this->current < $this->max;
    }

    /**
     * Array layout of the legacy getQueueFieldProjection().
     *
     * @return array{current: int, max: int, valid: bool}
     */
    public function toArray(): array
    {
        return ['current' => $this->current, 'max' => $this->max, 'valid' => $this->valid];
    }
}

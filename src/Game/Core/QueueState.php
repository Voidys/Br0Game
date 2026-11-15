<?php

namespace App\Game\Core;

/**
 * The working state of one ResourceUpdate run, shared with the queue processors: the user and planet rows being
 * updated, the deltas ("built") collected for the save step, the part of them that came from repair jobs, the target
 * time and a callback that brings the planet stock up to a given time (ResourceUpdate::UpdateResource()).
 *
 * Plain mutable value holder: arrays have copy-on-assign semantics, so a processor that wants to change the planet
 * writes `$st->planet` back explicitly, exactly as the pre-split code did with its properties.
 */
class QueueState
{
    /** @var array<string, mixed> */
    public array $user = [];

    /** @var array<string, mixed> */
    public array $planet = [];

    /** @var array<int|string, float|int> built levels / ships / resource deltas by element id */
    public array $builded = [];

    /** @var array<int|string, float|int> ships that came from a finished repair job */
    public array $repaired = [];

    /** target time of the update run (null before CalcResource()) */
    public int|float|null $time = null;

    /** @var \Closure(int|float, bool): void brings the planet stock to the given time (second argument: use eco hash) */
    public \Closure $updateResource;
}

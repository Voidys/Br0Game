<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Work that must not change how long the response takes (sending a reset mail only for accounts that exist would
 * tell an attacker which accounts exist): tasks added during the request run after the response was sent
 * (kernel.terminate; with PHP-FPM the client is already released by then). A failing task is logged, never thrown.
 */
final class DeferredWork
{
    /** @var list<callable(): void> */
    private array $tasks = [];

    /** @param callable(): void $task */
    public function add(callable $task): void
    {
        $this->tasks[] = $task;
    }

    /** Runs the queued tasks once the response has been sent (kernel.terminate). */
    #[AsEventListener(event: TerminateEvent::class)]
    public function run(): void
    {
        $tasks = $this->tasks;
        $this->tasks = [];
        foreach ($tasks as $task) {
            try {
                $task();
            } catch (\Throwable $exception) {
                error_log('Deferred task failed: ' . $exception->getMessage());
            }
        }
    }
}

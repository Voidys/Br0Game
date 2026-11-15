<?php

declare(strict_types=1);

namespace App\DataCollector;

use App\Game\Core\Singleton;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profiler panel "Game DB": the game's queries (App\Game\Core\Database runs them on the native PDO
 * connection, so Doctrine's panel does not see them), recorded in debug mode only.
 */
final class GameDatabaseCollector extends AbstractDataCollector
{
    public function __construct(
        private readonly Singleton $state,
    ) {}

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $queries = $this->state->queryLog ?? [];
        $this->data = [
            'queries' => $queries,
            'count' => count($queries),
            'time' => array_sum(array_column($queries, 'ms')),
        ];
    }

    public static function getTemplate(): ?string
    {
        return 'data_collector/game_db.html.twig';
    }

    public function getQueries(): array
    {
        return $this->data['queries'] ?? [];
    }

    public function getCount(): int
    {
        return $this->data['count'] ?? 0;
    }

    public function getTime(): float
    {
        return $this->data['time'] ?? 0.0;
    }

    /** queries that ran more than once (same SQL), most frequent first: candidates for caching/batching */
    public function getRepeated(): array
    {
        $counts = array_count_values(array_column($this->getQueries(), 'sql'));
        arsort($counts);

        return array_filter($counts, static fn(int $count): bool => $count > 1);
    }
}

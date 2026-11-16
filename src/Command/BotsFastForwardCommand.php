<?php

namespace App\Command;

use App\Game\Bots\BotEngine;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\EndOfRequest;
use App\Game\Core\StoredData;
use App\Runtime\GameKernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dev tool: lets the bots of a universe live through N minutes in seconds. Per simulated step (default 60 s) the whole bot world is
 * moved one step into the future by shifting the timestamps back (fleets and their events, the fleet log, the bots' schedule and
 * raid memory, production and build queues), then the bot engine ticks like the cron job would, and the new bot log lines are printed. Only the bots' own world is
 * shifted (not human sessions, other players' planets or the statistics), so it is a test aid, not a simulation of the whole game.
 * Runs only in the dev or test environment; --force only overrides the "bots disabled" check.
 */
#[AsCommand(name: 'app:bots:fast-forward', description: 'Dev only: runs the bots of a universe through N minutes of game time in seconds')]
final class BotsFastForwardCommand extends Command
{
    /** Bot raid memory keys that hold absolute times, per entry list. */
    private const MEMORY_TIMES = ['attacks' => ['impact', 'recheck_at', 'probe_sent', 'read_at', 'settle_at'], 'spies' => ['sent', 'arrive'], 'targets' => ['spy_at', 'skip_until', 'seen', 'last_hit']];

    public function __construct(
        private readonly GameKernel $kernel,
        private readonly BotEngine $engine,
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    /** Options: `--minutes` (30), `--universe` (1), `--step` seconds per step (60), `--limit` bots per tick, `--stats`, `--force`. */
    protected function configure(): void
    {
        $this
            ->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'Game minutes to run', '30')
            ->addOption('universe', null, InputOption::VALUE_REQUIRED, 'Universe id', '1')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Bots per tick (default: all)')
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'Seconds per step', '60')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Print SQL queries and wall time per bot tick instead of the bot log lines')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run even when bots are disabled in the universe (still only in the dev or test environment)');
    }

    /**
     * Boots the game, then for each step shifts the bot world back by the step, ticks the engine for the universe and prints the
     * bot log lines it produced (id order). Refuses outside APP_ENV=dev without --force.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            $output->writeln('<error>Only in the dev or test environment (not even with --force).</error>');

            return Command::FAILURE;
        }
        try {
            $this->kernel->bootCli();
        } catch (EndOfRequest) {
            $output->writeln('<error>The game is not ready.</error>');

            return Command::FAILURE;
        }
        $universe = (int) $input->getOption('universe');
        if (empty($this->configs->get($universe)->bots_enabled) && !$input->getOption('force')) {
            $output->writeln('<error>Bots are disabled in this universe (use --force to run anyway).</error>');

            return Command::FAILURE;
        }
        $step = max(1, (int) $input->getOption('step'));
        $steps = (int) ceil((int) $input->getOption('minutes') * 60 / $step);
        $last = (int) $this->db->selectSingle('SELECT COALESCE(MAX(id), 0) AS m FROM %%BOT_LOG%%;', [], 'm');

        $stats = (bool) $input->getOption('stats');
        $totals = ['steps' => 0, 'bots' => 0, 'queries' => 0, 'ms' => 0.0, 'max_ms' => 0.0];
        for ($i = 1; $i <= $steps; $i++) {
            $this->shift($universe, $step);
            $queries = $this->db->getQueryCounter();
            $start = microtime(true);
            $bots = $this->engine->tick($universe, (int) ($input->getOption('limit') ?: 1000));
            $ms = (microtime(true) - $start) * 1000;
            $totals['steps']++;
            $totals['bots'] += $bots;
            $totals['queries'] += $this->db->getQueryCounter() - $queries;
            $totals['ms'] += $ms;
            $totals['max_ms'] = max($totals['max_ms'], $ms);
            if ($stats) {
                $last = (int) $this->db->selectSingle('SELECT COALESCE(MAX(id), 0) AS m FROM %%BOT_LOG%%;', [], 'm');
                continue;
            }
            foreach ($this->db->select('SELECT l.id, l.time, l.action, l.detail, u.username FROM %%BOT_LOG%% l LEFT JOIN %%USERS%% u ON u.id = l.user_id WHERE l.id > :id ORDER BY l.id;', [':id' => $last]) as $line) {
                $last = (int) $line['id'];
                $output->writeln(sprintf('+%3d min  %-14s %-14s %s', intdiv($i * $step, 60), $line['username'], $line['action'], $line['detail']));
            }
        }

        if ($stats) {
            $output->writeln(sprintf(
                '%d ticks (cron runs), %d bots processed, %d queries (%.1f per bot), %.0f ms total, %.0f ms per tick, worst tick %.0f ms, %.1f ms per bot',
                $totals['steps'],
                $totals['bots'],
                $totals['queries'],
                $totals['queries'] / max(1, $totals['bots']),
                $totals['ms'],
                $totals['ms'] / max(1, $totals['steps']),
                $totals['max_ms'],
                $totals['ms'] / max(1, $totals['bots']),
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Moves the bots of a universe `$seconds` into the future: fleets that belong to a bot (times, events, log), the bots'
     * `next_tick` / `session_until` and the absolute times in their personality (raid memory, `_reg`).
     */
    private function shift(int $universe, int $seconds): void
    {
        $db = $this->db;
        $ids = array_column($db->select('SELECT f.id FROM %%FLEETS%% f INNER JOIN %%BOT_PLAYERS%% b ON b.user_id = f.owner WHERE f.universe = :u;', [':u' => $universe]), 'id');
        if ($ids !== []) {
            $in = implode(',', array_map('intval', $ids));
            $db->update("UPDATE %%FLEETS%% SET start_time = start_time - :s, arrival_time = arrival_time - :s, stay_time = stay_time - :s, return_time = return_time - :s WHERE id IN ($in);", [':s' => $seconds]);
            $db->update("UPDATE %%FLEETS_EVENT%% SET `time` = `time` - :s WHERE fleetID IN ($in);", [':s' => $seconds]);
            $db->update("UPDATE %%LOG_FLEETS%% SET start_time = start_time - :s, arrival_time = arrival_time - :s, stay_time = stay_time - :s, return_time = return_time - :s WHERE id IN ($in);", [':s' => $seconds]);
        }
        $this->shiftEconomy($universe, $seconds);
        foreach ($db->select('SELECT user_id, personality FROM %%BOT_PLAYERS%% WHERE universe = :u;', [':u' => $universe]) as $bot) {
            $personality = json_decode((string) $bot['personality'], true) ?: [];
            $db->update(
                'UPDATE %%BOT_PLAYERS%% SET personality = :p, next_tick = IF(next_tick > :s, next_tick - :s, 0), session_until = IF(session_until > :s, session_until - :s, 0) WHERE user_id = :id;',
                [':p' => json_encode($this->shiftMemory($personality, $seconds)), ':s' => $seconds, ':id' => $bot['user_id']],
            );
        }
    }

    /**
     * Lets production, the shipyard, building and research queues of the bots run `$seconds` further: `last_update` of their planets (the
     * resource update turns the elapsed time into production and ship building), the end times of the building and research queues and
     * `onlinetime`.
     */
    private function shiftEconomy(int $universe, int $seconds): void
    {
        $db = $this->db;
        $own = 'SELECT user_id FROM %%BOT_PLAYERS%% WHERE universe = :u';
        $db->update("UPDATE %%PLANETS%% SET last_update = last_update - :s, b_building = IF(b_building > :s, b_building - :s, b_building) WHERE id_owner IN ($own);", [':s' => $seconds, ':u' => $universe]);
        $db->update("UPDATE %%USERS%% SET onlinetime = onlinetime - :s, b_tech = IF(b_tech > :s, b_tech - :s, b_tech) WHERE id IN ($own);", [':s' => $seconds, ':u' => $universe]);
        foreach ($db->select("SELECT id, b_building_id FROM %%PLANETS%% WHERE id_owner IN ($own) AND b_building_id IS NOT NULL AND b_building_id != '';", [':u' => $universe]) as $row) {
            $db->update('UPDATE %%PLANETS%% SET b_building_id = :q WHERE id = :id;', [':q' => $this->shiftQueue((string) $row['b_building_id'], $seconds), ':id' => $row['id']]);
        }
        foreach ($db->select("SELECT id, b_tech_queue FROM %%USERS%% WHERE id IN ($own) AND b_tech_queue IS NOT NULL AND b_tech_queue != '';", [':u' => $universe]) as $row) {
            $db->update('UPDATE %%USERS%% SET b_tech_queue = :q WHERE id = :id;', [':q' => $this->shiftQueue((string) $row['b_tech_queue'], $seconds), ':id' => $row['id']]);
        }
    }

    /**
     * A stored queue (list of [element, level, time, end, ...]) with every end time moved back.
     */
    private function shiftQueue(string $stored, int $seconds): string
    {
        $queue = StoredData::decode($stored);
        if (!is_array($queue)) {
            return $stored;
        }
        foreach ($queue as $i => $item) {
            if (is_array($item) && isset($item[3]) && is_numeric($item[3])) {
                $queue[$i][3] = $item[3] - $seconds;
            }
        }

        return StoredData::encode($queue);
    }

    /**
     * The personality with the absolute times of the raid memory moved back by `$seconds`.
     *
     * @param array<string, mixed> $personality
     * @return array<string, mixed>
     */
    private function shiftMemory(array $personality, int $seconds): array
    {
        foreach (self::MEMORY_TIMES as $list => $fields) {
            foreach ($personality['_raid'][$list] ?? [] as $key => $entry) {
                foreach ($fields as $field) {
                    if (isset($entry[$field])) {
                        $personality['_raid'][$list][$key][$field] -= $seconds;
                    }
                }
            }
        }
        foreach (['start', 'until'] as $field) {
            if (isset($personality['_raid']['streak'][$field])) {
                $personality['_raid']['streak'][$field] -= $seconds;
            }
        }
        if (isset($personality['_raid']['scouted_at'])) {
            $personality['_raid']['scouted_at'] -= $seconds;
        }
        if (isset($personality['_reg'])) {
            $personality['_reg'] -= $seconds;
        }

        return $personality;
    }
}

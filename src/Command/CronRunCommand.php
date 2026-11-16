<?php

namespace App\Command;

use App\Runtime\GameKernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Game\Core\EndOfRequest;
use App\Game\Cronjob\Cronjob;

/**
 * Runs the game's due cronjobs (locking and rescheduling by App\Game\Cronjob\Cronjob).
 * Started every minute by a system cron (production) or the DDEV loop daemon (.ddev/config.yaml); every
 * run is a fresh process, as the game fixes its request time and state once per process.
 */
#[AsCommand(name: 'app:cron:run', description: 'Runs the due game cronjobs')]
final class CronRunCommand extends Command
{
    public function __construct(private readonly GameKernel $kernel, private readonly Cronjob $cronjobs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('job', null, InputOption::VALUE_REQUIRED, 'Run only this cronjob id (if it is due)');
    }

    /**
     * Boots the game in CLI mode, asks Cronjob for the jobs due now (optionally only `--job=<id>`) and executes them
     * one by one. Returns FAILURE (nothing run) when the bootstrap would redirect, i.e. installation or DB upgrade is pending.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->kernel->bootCli();
        } catch (EndOfRequest $end) {
            // e.g. pending database upgrade or missing config: the bootstrap would redirect
            $output->writeln($end->getTraceAsString(), OutputInterface::VERBOSITY_DEBUG);
            $output->writeln('<error>The game is not ready (installation or database upgrade pending).</error>');

            return Command::FAILURE;
        }

        $due = $this->cronjobs->getNeedTodoExecutedJobs();
        $only = $input->getOption('job');
        if ($only !== null) {
            $due = array_values(array_filter($due, static fn($id) => (int) $id === (int) $only));
        }

        foreach ($due as $cronjobId) {
            $output->writeln(sprintf('Running cronjob %d', $cronjobId), OutputInterface::VERBOSITY_VERBOSE);
            $this->cronjobs->execute((int) $cronjobId);
        }

        $output->writeln(sprintf('%d cronjob(s) executed.', count($due)));

        return Command::SUCCESS;
    }
}

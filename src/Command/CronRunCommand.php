<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\RunDueCronTasksMessage;
use App\MessageHandler\RunDueCronTasksHandler;
use App\Repository\CronTaskRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cron:run',
    description: 'Execute due 30-minute cron tasks to fetch and evaluate only new ads across providers.',
)]
class CronRunCommand extends Command
{
    public function __construct(
        private readonly RunDueCronTasksHandler $runDueCronTasksHandler,
        private readonly CronTaskRepository $cronTaskRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force execution of all active cron tasks regardless of nextRunAt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $forceAll = (bool) $input->getOption('force');

        $io->title('Listing Finder - Recurring Cron Runner');

        $stats = ($this->runDueCronTasksHandler)(new RunDueCronTasksMessage(forceAll: $forceAll));

        $tasks = $this->cronTaskRepository->findAllOrdered();
        if ([] !== $tasks) {
            $rows = [];
            foreach ($tasks as $task) {
                $sr = $task->getSearchRequest();
                $rows[] = [
                    (string) $task->getId(),
                    (string) $sr->getId(),
                    $sr->getTopic(),
                    sprintf('Every %d min', $task->getIntervalMinutes()),
                    (string) $task->getRunCount(),
                    $task->getLastRunAt()?->format('Y-m-d H:i:s') ?? 'N/A',
                    $task->getNextRunAt()->format('Y-m-d H:i:s'),
                ];
            }

            $io->table(
                ['Cron ID', 'Search ID', 'Topic', 'Interval', 'Runs', 'Last Run', 'Next Run'],
                $rows,
            );
        }

        $io->success(sprintf(
            'Cron sweep finished: %d cron task(s) executed, %d provider search message(s) dispatched.',
            $stats['tasks_executed'],
            $stats['messages_dispatched'],
        ));

        return Command::SUCCESS;
    }
}

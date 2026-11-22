<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CronTaskRepository;
use App\Repository\SearchRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:search:stop',
    description: 'Disable and remove the recurring 30-minute cron task for a topic (or list active cron tasks).',
    aliases: ['app:cron:remove', 'app:search:remove'],
)]
class SearchStopCommand extends Command
{
    public function __construct(
        private readonly SearchRequestRepository $searchRequestRepository,
        private readonly CronTaskRepository $cronTaskRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('search', InputArgument::OPTIONAL, 'SearchRequest ID or topic name to remove from cron table')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List all active cron tasks in the database')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Also delete the SearchRequest and all its stored listings/evaluations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('list')) {
            $this->renderActiveCronTasks($io);

            return Command::SUCCESS;
        }

        $identifierArg = $input->getArgument('search');
        $identifier = is_string($identifierArg) ? trim($identifierArg) : '';
        if ('' === $identifier) {
            $io->error('Please provide a topic name or SearchRequest ID to disable, or pass --list to view active cron tasks.');

            return Command::INVALID;
        }

        $searchRequest = $this->searchRequestRepository->findByIdentifierOrLatest($identifier);
        if (null === $searchRequest) {
            $io->error(sprintf('No search request found matching "%s".', $identifier));

            return Command::FAILURE;
        }

        $searchId = (int) $searchRequest->getId();
        $topic = $searchRequest->getTopic();
        $cronTask = $searchRequest->getCronTask();

        if ((bool) $input->getOption('purge')) {
            $this->entityManager->remove($searchRequest);
            $this->entityManager->flush();

            $io->success(sprintf(
                'Removed cron task and purged SearchRequest #%d ("%s") along with all linked ads and evaluations.',
                $searchId,
                $topic,
            ));

            return Command::SUCCESS;
        }

        if (null === $cronTask) {
            $io->warning(sprintf(
                'SearchRequest #%d ("%s") does not have an active entry in the cron_task table.',
                $searchId,
                $topic,
            ));

            return Command::SUCCESS;
        }

        $cronTaskId = (int) $cronTask->getId();
        $searchRequest->setCronTask(null);
        $this->entityManager->remove($cronTask);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Disabled and removed CronTask #%d for SearchRequest #%d ("%s"). Stored ads and evaluations remain available via app:search:results.',
            $cronTaskId,
            $searchId,
            $topic,
        ));

        return Command::SUCCESS;
    }

    private function renderActiveCronTasks(SymfonyStyle $io): void
    {
        $io->title('Listing Finder - Active Cron Tasks');

        $tasks = $this->cronTaskRepository->findAllOrdered();
        if ([] === $tasks) {
            $io->note('No active cron tasks are currently registered in the cron_task table.');

            return;
        }

        $rows = [];
        foreach ($tasks as $task) {
            $sr = $task->getSearchRequest();
            $rows[] = [
                (string) $task->getId(),
                (string) $sr->getId(),
                $sr->getTopic(),
                sprintf('%.2f EUR', $sr->getTargetPrice()),
                sprintf('Every %d min', $task->getIntervalMinutes()),
                (string) $task->getRunCount(),
                $task->getLastRunAt()?->format('Y-m-d H:i:s') ?? 'Never',
                $task->getNextRunAt()->format('Y-m-d H:i:s'),
            ];
        }

        $io->table(
            ['Cron ID', 'Search ID', 'Topic', 'Target Price', 'Interval', 'Runs', 'Last Run', 'Next Run'],
            $rows,
        );
    }
}

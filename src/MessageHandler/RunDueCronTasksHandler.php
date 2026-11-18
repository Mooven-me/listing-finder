<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RunDueCronTasksMessage;
use App\Repository\CronTaskRepository;
use App\Service\SearchDispatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunDueCronTasksHandler
{
    public function __construct(
        private readonly CronTaskRepository $cronTaskRepository,
        private readonly SearchDispatcherService $searchDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{tasks_executed: int, messages_dispatched: int}
     */
    public function __invoke(RunDueCronTasksMessage $message): array
    {
        $lock = $this->lockFactory->createLock('cron-tasks-runner', 60.0);
        if (!$lock->acquire(false)) {
            return ['tasks_executed' => 0, 'messages_dispatched' => 0];
        }

        try {
            $now = new \DateTimeImmutable();
            $cronTasks = $message->forceAll
                ? $this->cronTaskRepository->findAllOrdered()
                : $this->cronTaskRepository->findDueTasks($now);

            $tasksExecuted = 0;
            $messagesDispatched = 0;

            foreach ($cronTasks as $cronTask) {
                $searchRequest = $cronTask->getSearchRequest();
                $dispatched = $this->searchDispatcher->dispatchSearchJobs(
                    $searchRequest,
                    $cronTask->getLimitPerSubTopic(),
                );

                $cronTask->markExecuted($now);
                ++$tasksExecuted;
                $messagesDispatched += $dispatched;

                $this->logger->info('Executed scheduled cron search for topic "{topic}".', [
                    'cronTaskId' => $cronTask->getId(),
                    'searchRequestId' => $searchRequest->getId(),
                    'topic' => $searchRequest->getTopic(),
                    'dispatchedMessages' => $dispatched,
                    'nextRunAt' => $cronTask->getNextRunAt()->format(\DateTimeInterface::ATOM),
                ]);
            }

            if ($tasksExecuted > 0) {
                $this->entityManager->flush();
            }

            return [
                'tasks_executed' => $tasksExecuted,
                'messages_dispatched' => $messagesDispatched,
            ];
        } finally {
            $lock->release();
        }
    }
}

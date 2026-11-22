<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CronTask;
use App\Entity\SearchRequest;
use App\Enum\SearchStatus;
use App\Service\Ai\TopicExpansionAgent;
use App\Service\Provider\ListingProviderRegistry;
use App\Service\SearchDispatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:search:start',
    description: 'Expand a search topic with AI, dispatch initial provider searches, and register a 30-minute recurring cron task.',
)]
class SearchStartCommand extends Command
{
    public function __construct(
        private readonly TopicExpansionAgent $topicExpansionAgent,
        private readonly ListingProviderRegistry $providerRegistry,
        private readonly SearchDispatcherService $searchDispatcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('topic', InputArgument::REQUIRED, 'Product specification, model name, or detailed description of what you want')
            ->addOption('price', 'p', InputOption::VALUE_REQUIRED, 'Target price in EUR', '100')
            ->addOption('sub-topics', 's', InputOption::VALUE_REQUIRED, 'Number of AI search queries/titles to generate (1-15, or omit for Auto)')
            ->addOption('with-images', null, InputOption::VALUE_NONE, 'Enable AI image inspection for fetched listings')
            ->addOption('providers', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of providers (leboncoin,vinted,backmarket,mock)')
            ->addOption('provider-option', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Per-provider option as provider.key=value (e.g. -o leboncoin.allow_shipping=1 -o leboncoin.allow_near_me=1 -o leboncoin.location=Lyon)')
            ->addOption('requirements', 'r', InputOption::VALUE_REQUIRED, 'Additional user requirements (merged into specification)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max listings fetched per query per provider (0 = unlimited / all pages)', '25')
            ->addOption('unlimited', 'u', InputOption::VALUE_NONE, 'Fetch all available pages without an ad limit (equivalent to --limit=0)')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Recurring cron interval in minutes', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $topic = trim((string) $input->getArgument('topic'));
        if ('' === $topic) {
            $io->error('The search topic cannot be empty.');

            return Command::INVALID;
        }

        $targetPrice = (float) $input->getOption('price');
        if ($targetPrice <= 0) {
            $io->error('Target price must be greater than zero.');

            return Command::INVALID;
        }

        $enableImages = (bool) $input->getOption('with-images');
        $requirementsOption = $input->getOption('requirements');
        $requirements = is_string($requirementsOption) && '' !== trim($requirementsOption) ? trim($requirementsOption) : null;
        $subTopicsOption = $input->getOption('sub-topics');
        $requestedSubTopicsCount = is_string($subTopicsOption) && '' !== trim($subTopicsOption) && is_numeric(trim($subTopicsOption))
            ? max(1, min(15, (int) trim($subTopicsOption)))
            : null;
        $unlimited = (bool) $input->getOption('unlimited');
        $rawLimit = (int) $input->getOption('limit');
        $limit = ($unlimited || $rawLimit <= 0) ? null : $rawLimit;
        $intervalMinutes = max(1, (int) $input->getOption('interval'));

        $providersOption = $input->getOption('providers');
        $requestedProviders = null;
        if (is_string($providersOption) && '' !== trim($providersOption)) {
            $requestedProviders = array_values(array_filter(array_map('trim', explode(',', $providersOption))));
        }

        try {
            $resolvedProviders = $this->providerRegistry->resolveProviders($requestedProviders);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $providerNames = array_map(static fn ($provider): string => $provider->getName(), $resolvedProviders);

        // Parse repeatable --provider-option provider.key=value flags
        /** @var array<string, array<string, mixed>> $rawProviderOptions */
        $rawProviderOptions = [];
        $cliProviderOptions = $input->getOption('provider-option');
        if (\is_array($cliProviderOptions)) {
            foreach ($cliProviderOptions as $expr) {
                if (!\is_string($expr) || 1 !== preg_match('/^([a-z0-9_-]+)\.([a-z0-9_-]+)=(.*)$/i', trim($expr), $m)) {
                    $io->warning(sprintf('Ignoring malformed --provider-option "%s" (expected provider.key=value).', (string) $expr));

                    continue;
                }
                $rawProviderOptions[strtolower($m[1])][strtolower($m[2])] = $m[3];
            }
        }
        $normalizedProviderOptions = $this->providerRegistry->normalizeProviderOptions($rawProviderOptions, $providerNames);

        $io->title('Listing Finder - Search & Recurring Cron Setup');

        $io->section('Step 1: Expanding Topic via AI Sub-Process');
        $io->text(sprintf('Analyzing topic "%s" to generate marketplace search tags...', $topic));

        $expansionResult = $this->topicExpansionAgent->expandTopic($topic, $requirements, $requestedSubTopicsCount);
        $subTopics = $expansionResult->subTopics;

        $searchRequest = new SearchRequest(
            topic: $topic,
            targetPrice: $targetPrice,
            enableImages: $enableImages,
            userRequirements: $requirements,
            enabledProviders: $providerNames,
            providerOptions: $normalizedProviderOptions,
        );
        $searchRequest->setGeneratedSubTopics($subTopics);
        $searchRequest->setStatus(SearchStatus::Processing);

        $cronTask = new CronTask(
            searchRequest: $searchRequest,
            intervalMinutes: $intervalMinutes,
            limitPerSubTopic: $limit,
        );
        $searchRequest->setCronTask($cronTask);
        $this->entityManager->persist($searchRequest);
        $this->entityManager->persist($cronTask);
        $this->entityManager->flush();

        $io->listing($subTopics);

        $io->section('Step 2: Dispatching Initial Provider Searches & Registering 30-Minute Cron');

        $dispatchedCount = $this->searchDispatcher->dispatchSearchJobs($searchRequest, $limit);
        $searchRequestId = (int) $searchRequest->getId();

        $io->definitionList(
            ['Search Request ID' => (string) $searchRequestId],
            ['Cron Task ID' => (string) $cronTask->getId()],
            ['Main Topic' => $topic],
            ['Target Price' => sprintf('%.2f EUR', $targetPrice)],
            ['Image Analysis Enabled' => $enableImages ? 'Yes' : 'No'],
            ['User Requirements' => $requirements ?? 'None specified'],
            ['Providers' => implode(', ', $providerNames)],
            ['Max Ads / Query' => null !== $limit ? (string) $limit : 'Unlimited (All pages)'],
            ['Sub-Topics Generated' => (string) count($subTopics)],
            ['Initial Jobs Queued' => (string) $dispatchedCount],
            ['Cron Schedule' => sprintf('Every %d minutes (Incremental: new ads only)', $cronTask->getIntervalMinutes())],
            ['Next Scheduled Run' => $cronTask->getNextRunAt()->format('Y-m-d H:i:s')],
        );

        $io->note([
            'The background worker automatically consumes both the 30-minute scheduler and async queue:',
            'php bin/console messenger:consume scheduler_default async --time-limit=60 -vv',
            sprintf('View results (by category): php bin/console app:search:results %d --by-category', $searchRequestId),
            sprintf('View results (by score):    php bin/console app:search:results %d --by-score', $searchRequestId),
            sprintf('View a single category:     php bin/console app:search:results %d must_buy  (or: good | pass)', $searchRequestId),
            sprintf('Stop/remove cron task:      php bin/console app:search:stop %d', $searchRequestId),
        ]);

        $io->success(sprintf(
            'Search task #%d initialized (%d provider jobs queued) and registered in cron_task table (#%d, every %d min).',
            $searchRequestId,
            $dispatchedCount,
            (int) $cronTask->getId(),
            $cronTask->getIntervalMinutes(),
        ));

        return Command::SUCCESS;
    }
}

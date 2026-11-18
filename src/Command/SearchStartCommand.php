<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SearchRequest;
use App\Enum\SearchStatus;
use App\Message\FetchSubTopicListingsMessage;
use App\Service\Ai\TopicExpansionAgent;
use App\Service\Provider\ListingProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:search:start',
    description: 'Expand a search topic with AI and dispatch async marketplace fetching and evaluation jobs.',
)]
class SearchStartCommand extends Command
{
    public function __construct(
        private readonly TopicExpansionAgent $topicExpansionAgent,
        private readonly ListingProviderRegistry $providerRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('topic', InputArgument::REQUIRED, 'Main product topic or theme to search (e.g. "sfp+ switch")')
            ->addOption('price', 'p', InputOption::VALUE_REQUIRED, 'Target price in EUR', '100')
            ->addOption('with-images', null, InputOption::VALUE_NONE, 'Enable AI image inspection for fetched listings')
            ->addOption('providers', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of providers (leboncoin,vinted,backmarket,mock)')
            ->addOption('requirements', 'r', InputOption::VALUE_REQUIRED, 'Additional user requirements or preferences')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max listings fetched per sub-topic per provider', '5');
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
        $limit = max(1, (int) $input->getOption('limit'));

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

        $io->title('Listing Finder - Search Setup');

        $searchRequest = new SearchRequest(
            topic: $topic,
            targetPrice: $targetPrice,
            enableImages: $enableImages,
            userRequirements: $requirements,
            enabledProviders: $providerNames,
        );
        $searchRequest->setStatus(SearchStatus::Expanding);

        $this->entityManager->persist($searchRequest);
        $this->entityManager->flush();

        $io->section('Step 1: Expanding Topic via AI Sub-Process');
        $io->text(sprintf('Analyzing topic "%s" to generate marketplace search tags...', $topic));

        $expansionResult = $this->topicExpansionAgent->expandTopic($topic, $requirements);
        $subTopics = $expansionResult->subTopics;

        $searchRequest->setGeneratedSubTopics($subTopics);
        $searchRequest->setStatus(SearchStatus::Processing);
        $this->entityManager->flush();

        $io->listing($subTopics);

        $io->section('Step 2: Dispatching Async PostgreSQL Messenger Tasks');

        $dispatchedCount = 0;
        $searchRequestId = (int) $searchRequest->getId();

        foreach ($subTopics as $subTopic) {
            foreach ($providerNames as $providerName) {
                $this->messageBus->dispatch(new FetchSubTopicListingsMessage(
                    searchRequestId: $searchRequestId,
                    subTopic: $subTopic,
                    providerName: $providerName,
                    limit: $limit,
                ));
                ++$dispatchedCount;
            }
        }

        $io->definitionList(
            ['Search Request ID' => (string) $searchRequestId],
            ['Main Topic' => $topic],
            ['Target Price' => sprintf('%.2f EUR', $targetPrice)],
            ['Image Analysis Enabled' => $enableImages ? 'Yes' : 'No'],
            ['User Requirements' => $requirements ?? 'None specified'],
            ['Providers' => implode(', ', $providerNames)],
            ['Sub-Topics Generated' => (string) count($subTopics)],
            ['Async Messages Queued' => (string) $dispatchedCount],
        );

        $io->note([
            'Run the Messenger worker to process the queued tasks asynchronously:',
            'php bin/console messenger:consume async --time-limit=60 -vv',
            sprintf('Then inspect the results with: php bin/console app:search:results %d', $searchRequestId),
        ]);

        $io->success(sprintf('Search task #%d initialized and %d provider search jobs queued.', $searchRequestId, $dispatchedCount));

        return Command::SUCCESS;
    }
}

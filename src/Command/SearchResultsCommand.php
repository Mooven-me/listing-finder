<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ListingEvaluation;
use App\Enum\DealCategory;
use App\Repository\ListingEvaluationRepository;
use App\Repository\SearchRequestRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Serializer\SerializerInterface;

#[AsCommand(
    name: 'app:search:results',
    description: 'Display AI-evaluated marketplace listings grouped into MUST BUY (9-10/10) and GOOD (7-8/10).',
)]
class SearchResultsCommand extends Command
{
    public function __construct(
        private readonly SearchRequestRepository $searchRequestRepository,
        private readonly ListingEvaluationRepository $evaluationRepository,
        private readonly SerializerInterface $serializer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('search', InputArgument::OPTIONAL, 'SearchRequest ID or topic substring (defaults to latest search)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Also display PASS listings scoring below 70/100')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifierArg = $input->getArgument('search');
        $identifier = is_string($identifierArg) ? $identifierArg : null;

        $searchRequest = $this->searchRequestRepository->findByIdentifierOrLatest($identifier);
        if (null === $searchRequest) {
            $io->error('No matching search request found in database. Run "php bin/console app:search:start <topic>" first.');

            return Command::FAILURE;
        }

        $allEvaluations = $this->evaluationRepository->findForSearchRequest($searchRequest);

        $mustBuy = array_values(array_filter(
            $allEvaluations,
            static fn (ListingEvaluation $eval): bool => DealCategory::MustBuy === $eval->getCategory(),
        ));

        $good = array_values(array_filter(
            $allEvaluations,
            static fn (ListingEvaluation $eval): bool => DealCategory::Good === $eval->getCategory(),
        ));

        $pass = array_values(array_filter(
            $allEvaluations,
            static fn (ListingEvaluation $eval): bool => DealCategory::Pass === $eval->getCategory(),
        ));

        $cronTask = $searchRequest->getCronTask();
        $cronSummary = null !== $cronTask
            ? sprintf('ACTIVE (Every %d min | Runs: %d | Next: %s)', $cronTask->getIntervalMinutes(), $cronTask->getRunCount(), $cronTask->getNextRunAt()->format('Y-m-d H:i:s'))
            : 'DISABLED (Removed from cron_task table)';

        if ((bool) $input->getOption('json')) {
            $payload = [
                'search_request' => [
                    'id' => $searchRequest->getId(),
                    'topic' => $searchRequest->getTopic(),
                    'target_price' => $searchRequest->getTargetPrice(),
                    'enable_images' => $searchRequest->isEnableImages(),
                    'sub_topics' => $searchRequest->getGeneratedSubTopics(),
                    'providers' => $searchRequest->getEnabledProviders(),
                    'status' => $searchRequest->getStatus()->value,
                    'cron_active' => null !== $cronTask,
                    'cron_interval_minutes' => $cronTask?->getIntervalMinutes(),
                    'cron_next_run_at' => $cronTask?->getNextRunAt()->format(\DateTimeInterface::ATOM),
                ],
                'must_buy' => array_map($this->normalizeEvaluation(...), $mustBuy),
                'good' => array_map($this->normalizeEvaluation(...), $good),
            ];

            if ((bool) $input->getOption('all')) {
                $payload['pass'] = array_map($this->normalizeEvaluation(...), $pass);
            }

            $output->writeln($this->serializer->serialize($payload, 'json', ['json_encode_options' => \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES]));

            return Command::SUCCESS;
        }

        $io->title(sprintf('Listing Finder - Results for "%s" (Search #%d)', $searchRequest->getTopic(), (int) $searchRequest->getId()));

        $io->definitionList(
            ['Search ID' => (string) $searchRequest->getId()],
            ['Topic' => $searchRequest->getTopic()],
            ['Target Price' => sprintf('%.2f EUR', $searchRequest->getTargetPrice())],
            ['Status' => strtoupper($searchRequest->getStatus()->value)],
            ['Recurring Cron Task' => $cronSummary],
            ['Sub-Topics Searched' => implode(', ', $searchRequest->getGeneratedSubTopics())],
            ['Providers' => implode(', ', $searchRequest->getEnabledProviders())],
            ['Total Stored Ads' => (string) count($searchRequest->getListings())],
            ['Total Evaluated Ads' => (string) count($allEvaluations)],
            ['MUST BUY Count (90-100 / 9-10)' => (string) count($mustBuy)],
            ['GOOD Count (70-89 / 7-8)' => (string) count($good)],
        );

        if ([] === $allEvaluations) {
            $io->warning([
                'No evaluated listings are stored yet for this search request.',
                'Ensure the Messenger worker has processed the queue: php bin/console messenger:consume async --time-limit=60',
            ]);

            return Command::SUCCESS;
        }

        $this->renderCategorySection($io, 'MUST BUY (Score 90-100 / 100 | 9.0-10.0 / 10)', $mustBuy);
        $this->renderCategorySection($io, 'GOOD DEALS (Score 70-89 / 100 | 7.0-8.9 / 10)', $good);

        if ((bool) $input->getOption('all')) {
            $this->renderCategorySection($io, 'PASS / OTHER ADS (Score < 70 / 100 | < 7.0 / 10)', $pass);
        }

        $io->success(sprintf(
            'Displayed %d MUST BUY and %d GOOD listings out of %d total evaluated ads.',
            count($mustBuy),
            count($good),
            count($allEvaluations),
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<ListingEvaluation> $evaluations
     */
    private function renderCategorySection(SymfonyStyle $io, string $sectionTitle, array $evaluations): void
    {
        $io->section($sectionTitle);

        if ([] === $evaluations) {
            $io->note('No listings matched this score bracket.');

            return;
        }

        $rows = [];
        foreach ($evaluations as $eval) {
            $listing = $eval->getListing();
            $workingLabel = match ($eval->isWorking()) {
                true => 'Yes',
                false => 'No',
                null => 'Unknown',
            };

            $rows[] = [
                sprintf('%d/100 (%.1f/10)', $eval->getScoreOutOf100(), $eval->getScoreOutOf10()),
                strtoupper($listing->getProvider()),
                mb_strimwidth($listing->getTitle(), 0, 46, '...'),
                sprintf('%.2f %s', $listing->getPrice(), $listing->getCurrency()),
                $workingLabel,
                $eval->getEstimatedAge() ?? 'N/A',
                $eval->isImageAnalyzed() ? 'Yes' : 'No',
                sprintf(
                    'P:%d W:%d A:%d R:%d',
                    $eval->getPriceScore(),
                    $eval->getWorkingConditionScore(),
                    $eval->getProductAgeScore(),
                    $eval->getRelevanceScore(),
                ),
            ];
        }

        $io->table(
            ['Score', 'Provider', 'Title', 'Price', 'Works', 'Est. Age', 'Img AI', 'Sub-Scores'],
            $rows,
        );

        foreach ($evaluations as $eval) {
            $listing = $eval->getListing();
            $io->writeln(sprintf(
                '  * [%d/100 | %s] %s (%.2f %s) - %s',
                $eval->getScoreOutOf100(),
                strtoupper($listing->getProvider()),
                $listing->getTitle(),
                $listing->getPrice(),
                $listing->getCurrency(),
                $listing->getUrl(),
            ));
            $io->writeln(sprintf('    AI Analysis: %s', $eval->getAiReasoning()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeEvaluation(ListingEvaluation $eval): array
    {
        $listing = $eval->getListing();

        return [
            'score_out_of_100' => $eval->getScoreOutOf100(),
            'score_out_of_10' => $eval->getScoreOutOf10(),
            'category' => $eval->getCategory()->value,
            'sub_scores' => [
                'price' => $eval->getPriceScore(),
                'working_condition' => $eval->getWorkingConditionScore(),
                'product_age' => $eval->getProductAgeScore(),
                'relevance' => $eval->getRelevanceScore(),
            ],
            'is_working' => $eval->isWorking(),
            'estimated_age' => $eval->getEstimatedAge(),
            'image_analyzed' => $eval->isImageAnalyzed(),
            'reasoning' => $eval->getAiReasoning(),
            'listing' => [
                'id' => $listing->getId(),
                'provider' => $listing->getProvider(),
                'external_id' => $listing->getExternalId(),
                'sub_topic_used' => $listing->getSearchTopicUsed(),
                'title' => $listing->getTitle(),
                'price' => $listing->getPrice(),
                'currency' => $listing->getCurrency(),
                'condition' => $listing->getConditionState(),
                'url' => $listing->getUrl(),
                'image_urls' => $listing->getImageUrls(),
            ],
        ];
    }
}

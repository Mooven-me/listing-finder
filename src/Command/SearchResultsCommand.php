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
            ->addOption('sort', 's', InputOption::VALUE_REQUIRED, 'Sort mode: "category" (grouped into MUST BUY / GOOD / PASS) or "score" (single flat list sorted by score)', 'category')
            ->addOption('by-category', 'c', InputOption::VALUE_NONE, 'Shortcut for --sort=category (group results by category)')
            ->addOption('by-score', null, InputOption::VALUE_NONE, 'Shortcut for --sort=score (sort all results by score in a unified list)')
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

        $sortMode = strtolower(trim((string) $input->getOption('sort')));
        if ((bool) $input->getOption('by-score')) {
            $sortMode = 'score';
        } elseif ((bool) $input->getOption('by-category')) {
            $sortMode = 'category';
        }

        if (!\in_array($sortMode, ['category', 'categories', 'score'], true)) {
            $io->error(sprintf('Invalid --sort value "%s". Allowed values: "category", "score".', $sortMode));

            return Command::INVALID;
        }

        $isSortByScore = 'score' === $sortMode;
        $showAll = (bool) $input->getOption('all');

        $allEvaluations = $this->evaluationRepository->findForSearchRequest($searchRequest);

        // Ensure evaluations are sorted by score DESC, then price ASC
        usort(
            $allEvaluations,
            static function (ListingEvaluation $a, ListingEvaluation $b): int {
                $scoreCmp = $b->getScoreOutOf100() <=> $a->getScoreOutOf100();
                if (0 !== $scoreCmp) {
                    return $scoreCmp;
                }

                return $a->getListing()->getPrice() <=> $b->getListing()->getPrice();
            },
        );

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
                    'sort_mode' => $isSortByScore ? 'score' : 'category',
                    'cron_active' => null !== $cronTask,
                    'cron_interval_minutes' => $cronTask?->getIntervalMinutes(),
                    'cron_next_run_at' => $cronTask?->getNextRunAt()->format(\DateTimeInterface::ATOM),
                ],
                'must_buy' => array_map($this->normalizeEvaluation(...), $mustBuy),
                'good' => array_map($this->normalizeEvaluation(...), $good),
            ];

            if ($showAll) {
                $payload['pass'] = array_map($this->normalizeEvaluation(...), $pass);
            }

            if ($isSortByScore) {
                $flatList = $showAll ? $allEvaluations : array_merge($mustBuy, $good);
                $payload['results_by_score'] = array_map($this->normalizeEvaluation(...), $flatList);
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
            ['Sort Mode' => $isSortByScore ? 'BY SCORE (High -> Low)' : 'BY CATEGORY (MUST BUY / GOOD / PASS)'],
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

        if ($isSortByScore) {
            $flatEvaluations = $showAll ? $allEvaluations : array_merge($mustBuy, $good);
            $sectionLabel = $showAll
                ? 'ALL EVALUATED ADS — SORTED BY SCORE (100 -> 0)'
                : 'TOP DEALS (SCORE >= 70/100) — SORTED BY SCORE';
            $this->renderCategorySection($io, $sectionLabel, $flatEvaluations, true);
        } else {
            $this->renderCategorySection($io, 'MUST BUY (Score 90-100 / 100 | 9.0-10.0 / 10)', $mustBuy, false);
            $this->renderCategorySection($io, 'GOOD DEALS (Score 70-89 / 100 | 7.0-8.9 / 10)', $good, false);

            if ($showAll) {
                $this->renderCategorySection($io, 'PASS / OTHER ADS (Score < 70 / 100 | < 7.0 / 10)', $pass, false);
            }
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
    private function renderCategorySection(
        SymfonyStyle $io,
        string $sectionTitle,
        array $evaluations,
        bool $includeCategoryColumn = false,
    ): void {
        $io->section($sectionTitle);

        if ([] === $evaluations) {
            $io->note('No listings matched this score bracket.');

            return;
        }

        $rows = [];
        foreach ($evaluations as $eval) {
            $listing = $eval->getListing();
            $workingLabel = match ($eval->isWorking()) {
                true => '<fg=green>Yes</>',
                false => '<fg=red;options=bold>No</>',
                null => '<fg=yellow>Unknown</>',
            };

            $scoreBadge = match ($eval->getCategory()) {
                DealCategory::MustBuy => sprintf('<fg=green;options=bold>%d/100 (%.1f/10)</>', $eval->getScoreOutOf100(), $eval->getScoreOutOf10()),
                DealCategory::Good => sprintf('<fg=cyan;options=bold>%d/100 (%.1f/10)</>', $eval->getScoreOutOf100(), $eval->getScoreOutOf10()),
                DealCategory::Pass => sprintf('<fg=yellow>%d/100 (%.1f/10)</>', $eval->getScoreOutOf100(), $eval->getScoreOutOf10()),
            };

            $row = [
                $scoreBadge,
            ];

            if ($includeCategoryColumn) {
                $row[] = match ($eval->getCategory()) {
                    DealCategory::MustBuy => '<bg=green;fg=black;options=bold> MUST BUY </>',
                    DealCategory::Good => '<bg=cyan;fg=black;options=bold> GOOD </>',
                    DealCategory::Pass => '<bg=yellow;fg=black> PASS </>',
                };
            }

            $row[] = strtoupper($listing->getProvider());
            $row[] = mb_strimwidth($listing->getTitle(), 0, 46, '...');
            $row[] = sprintf('<fg=green;options=bold>%.2f %s</>', $listing->getPrice(), $listing->getCurrency());
            $row[] = $workingLabel;
            $row[] = $eval->getEstimatedAge() ?? 'N/A';
            $row[] = $eval->isImageAnalyzed() ? 'Yes' : 'No';
            $row[] = sprintf(
                'P:%d W:%d A:%d R:%d',
                $eval->getPriceScore(),
                $eval->getWorkingConditionScore(),
                $eval->getProductAgeScore(),
                $eval->getRelevanceScore(),
            );

            $rows[] = $row;
        }

        $headers = ['Score'];
        if ($includeCategoryColumn) {
            $headers[] = 'Category';
        }
        $headers = array_merge($headers, ['Provider', 'Title', 'Price', 'Works', 'Est. Age', 'Img AI', 'Sub-Scores']);

        $io->table($headers, $rows);

        $io->writeln('<options=bold;fg=white>Detailed Listings & AI Analysis:</>');
        $io->newLine();

        foreach ($evaluations as $index => $eval) {
            $listing = $eval->getListing();
            $categoryStyle = match ($eval->getCategory()) {
                DealCategory::MustBuy => 'fg=black;bg=green;options=bold',
                DealCategory::Good => 'fg=black;bg=cyan;options=bold',
                DealCategory::Pass => 'fg=black;bg=yellow;options=bold',
            };

            $workingText = match ($eval->isWorking()) {
                true => '<fg=green;options=bold>Yes (Functional)</>',
                false => '<fg=red;options=bold>No (Defective / HS)</>',
                null => '<fg=yellow>Unknown</>',
            };

            $io->writeln(sprintf(
                ' <%s> #%d | %s | %d/100 (%.1f/10) | %s </> <options=bold;fg=white>%s</> — <fg=green;options=bold>%.2f %s</>',
                $categoryStyle,
                $index + 1,
                $eval->getCategory()->label(),
                $eval->getScoreOutOf100(),
                $eval->getScoreOutOf10(),
                strtoupper($listing->getProvider()),
                $listing->getTitle(),
                $listing->getPrice(),
                $listing->getCurrency(),
            ));

            $io->definitionList(
                ['URL' => sprintf('<fg=cyan;options=underscore>%s</>', $listing->getUrl())],
                ['Price & State' => sprintf(
                    '<fg=green;options=bold>%.2f %s</> (Target: %.2f EUR) | Condition: %s | Works: %s | Est. Age: %s',
                    $listing->getPrice(),
                    $listing->getCurrency(),
                    $eval->getSearchRequest()->getTargetPrice(),
                    $listing->getConditionState() ?? 'Unspecified',
                    $workingText,
                    $eval->getEstimatedAge() ?? 'N/A',
                )],
                ['Sub-Scores' => sprintf(
                    'Price: <options=bold>%d</>/100 | Working: <options=bold>%d</>/100 | Age: <options=bold>%d</>/100 | Relevance: <options=bold>%d</>/100 (Search tag: "%s")',
                    $eval->getPriceScore(),
                    $eval->getWorkingConditionScore(),
                    $eval->getProductAgeScore(),
                    $eval->getRelevanceScore(),
                    $listing->getSearchTopicUsed(),
                )],
            );

            $io->block(
                $eval->getAiReasoning(),
                'AI ANALYSIS',
                'fg=white;bg=blue',
                '  ',
                true,
            );
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

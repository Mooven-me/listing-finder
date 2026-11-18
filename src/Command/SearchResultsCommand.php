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
            ->addArgument('category', InputArgument::OPTIONAL, 'Filter by a single category: "must_buy", "extremely_good", "good", "bad", "poor"')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Filter by a single category: "must_buy", "extremely_good", "good", "bad", "poor"')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Also display BAD and POOR listings scoring below 50/100')
            ->addOption('sort', 's', InputOption::VALUE_REQUIRED, 'Sort mode: "category" or "score"', 'category')
            ->addOption('by-category', 'c', InputOption::VALUE_NONE, 'Shortcut for --sort=category (group results by category)')
            ->addOption('by-score', null, InputOption::VALUE_NONE, 'Shortcut for --sort=score (sort all results by score in a unified list)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifierArg = $input->getArgument('search');
        $identifier = is_string($identifierArg) && '' !== trim($identifierArg) ? trim($identifierArg) : null;

        $categoryInput = $input->getOption('category') ?? $input->getArgument('category');
        $rawCategoryFilter = is_string($categoryInput) && '' !== trim($categoryInput) ? trim($categoryInput) : null;

        // Allow passing the category directly as the first argument when no search ID is specified (e.g. `app:search:results must_buy`)
        if (null === $rawCategoryFilter && null !== $identifier && null !== $this->parseCategoryFilter($identifier)) {
            $rawCategoryFilter = $identifier;
            $identifier = null;
        }

        $selectedCategory = null;
        if (null !== $rawCategoryFilter) {
            $selectedCategory = $this->parseCategoryFilter($rawCategoryFilter);
            if (null === $selectedCategory) {
                $io->error(sprintf(
                    'Invalid category "%s". Allowed values: "must_buy", "extremely_good", "good", "bad", "poor".',
                    $rawCategoryFilter,
                ));

                return Command::INVALID;
            }
        }

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
        $showAll = (bool) $input->getOption('all') || \in_array($selectedCategory, [DealCategory::Bad, DealCategory::Poor], true);

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

        $extremelyGood = array_values(array_filter(
            $allEvaluations,
            static fn (ListingEvaluation $eval): bool => DealCategory::ExtremelyGood === $eval->getCategory(),
        ));

        $good = array_values(array_filter(
            $allEvaluations,
            static fn (ListingEvaluation $eval): bool => DealCategory::Good === $eval->getCategory(),
        ));

        $bad = array_values(array_filter(
            $allEvaluations,
            static fn (ListingEvaluation $eval): bool => DealCategory::Bad === $eval->getCategory(),
        ));

        $poor = array_values(array_filter(
            $allEvaluations,
            static fn (ListingEvaluation $eval): bool => DealCategory::Poor === $eval->getCategory(),
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
                    'category_filter' => $selectedCategory?->value,
                    'cron_active' => null !== $cronTask,
                    'cron_interval_minutes' => $cronTask?->getIntervalMinutes(),
                    'cron_next_run_at' => $cronTask?->getNextRunAt()->format(\DateTimeInterface::ATOM),
                ],
                'must_buy' => null === $selectedCategory || DealCategory::MustBuy === $selectedCategory
                    ? array_map($this->normalizeEvaluation(...), $mustBuy)
                    : [],
                'extremely_good' => null === $selectedCategory || DealCategory::ExtremelyGood === $selectedCategory
                    ? array_map($this->normalizeEvaluation(...), $extremelyGood)
                    : [],
                'good' => null === $selectedCategory || DealCategory::Good === $selectedCategory
                    ? array_map($this->normalizeEvaluation(...), $good)
                    : [],
            ];

            if ($showAll) {
                $payload['bad'] = null === $selectedCategory || DealCategory::Bad === $selectedCategory
                    ? array_map($this->normalizeEvaluation(...), $bad)
                    : [];
                $payload['poor'] = null === $selectedCategory || DealCategory::Poor === $selectedCategory
                    ? array_map($this->normalizeEvaluation(...), $poor)
                    : [];
            }

            if ($isSortByScore) {
                $flatList = match ($selectedCategory) {
                    DealCategory::MustBuy => $mustBuy,
                    DealCategory::ExtremelyGood => $extremelyGood,
                    DealCategory::Good => $good,
                    DealCategory::Bad => $bad,
                    DealCategory::Poor => $poor,
                    null => $showAll ? $allEvaluations : array_merge($mustBuy, $extremelyGood, $good),
                };
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
            ['Sort Mode' => $isSortByScore ? 'BY SCORE (High -> Low)' : 'BY CATEGORY (5 Tiers)'],
            ['Category Filter' => null !== $selectedCategory ? $selectedCategory->label() : 'ALL CATEGORIES'],
            ['Recurring Cron Task' => $cronSummary],
            ['Sub-Topics Searched' => implode(', ', $searchRequest->getGeneratedSubTopics())],
            ['Providers' => implode(', ', $searchRequest->getEnabledProviders())],
            ['Total Stored Ads' => (string) count($searchRequest->getListings())],
            ['Total Evaluated Ads' => (string) count($allEvaluations)],
            ['MUST BUY (90-100)' => (string) count($mustBuy)],
            ['EXTREMELY GOOD (70-89)' => (string) count($extremelyGood)],
            ['GOOD (50-69)' => (string) count($good)],
            ['BAD (30-49)' => (string) count($bad)],
            ['POOR (0-29)' => (string) count($poor)],
        );

        if ([] === $allEvaluations) {
            $io->warning([
                'No evaluated listings are stored yet for this search request.',
                'Ensure the Messenger worker has processed the queue: php bin/console messenger:consume async --time-limit=60',
            ]);

            return Command::SUCCESS;
        }

        if (null !== $selectedCategory) {
            [$sectionTitle, $filteredEvaluations] = match ($selectedCategory) {
                DealCategory::MustBuy => ['MUST BUY (Score 90-100)', $mustBuy],
                DealCategory::ExtremelyGood => ['EXTREMELY GOOD (Score 70-89)', $extremelyGood],
                DealCategory::Good => ['GOOD (Score 50-69)', $good],
                DealCategory::Bad => ['BAD (Score 30-49)', $bad],
                DealCategory::Poor => ['POOR (Score 0-29)', $poor],
            };

            $this->renderCategorySection($io, $sectionTitle, $filteredEvaluations, $isSortByScore);

            $io->success(sprintf(
                'Displayed %d listing(s) in category "%s" out of %d total evaluated ads.',
                count($filteredEvaluations),
                $selectedCategory->label(),
                count($allEvaluations),
            ));

            return Command::SUCCESS;
        }

        if ($isSortByScore) {
            $flatEvaluations = $showAll ? $allEvaluations : array_merge($mustBuy, $extremelyGood, $good);
            $sectionLabel = $showAll
                ? 'ALL EVALUATED ADS — SORTED BY SCORE (100 -> 0)'
                : 'RECOMMENDED DEALS (SCORE >= 50/100) — SORTED BY SCORE';
            $this->renderCategorySection($io, $sectionLabel, $flatEvaluations, true);
        } else {
            $this->renderCategorySection($io, 'MUST BUY (Score 90-100)', $mustBuy, false);
            $this->renderCategorySection($io, 'EXTREMELY GOOD (Score 70-89)', $extremelyGood, false);
            $this->renderCategorySection($io, 'GOOD (Score 50-69)', $good, false);

            if ($showAll) {
                $this->renderCategorySection($io, 'BAD (Score 30-49)', $bad, false);
                $this->renderCategorySection($io, 'POOR (Score 0-29)', $poor, false);
            }
        }

        $io->success(sprintf(
            'Displayed %d MUST BUY, %d EXTREMELY GOOD, and %d GOOD listings out of %d total evaluated ads.',
            count($mustBuy),
            count($extremelyGood),
            count($good),
            count($allEvaluations),
        ));

        return Command::SUCCESS;
    }

    private function parseCategoryFilter(string $value): ?DealCategory
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return match ($normalized) {
            'must_buy', 'mustbuy', 'must' => DealCategory::MustBuy,
            'extremely_good', 'extremelygood', 'ext_good' => DealCategory::ExtremelyGood,
            'good', 'good_deals', 'good_deal' => DealCategory::Good,
            'bad' => DealCategory::Bad,
            'poor', 'pass', 'other' => DealCategory::Poor,
            default => null,
        };
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
                DealCategory::MustBuy => sprintf('<fg=green;options=bold>%d/100</>', $eval->getScoreOutOf100()),
                DealCategory::ExtremelyGood => sprintf('<fg=cyan;options=bold>%d/100</>', $eval->getScoreOutOf100()),
                DealCategory::Good => sprintf('<fg=white;options=bold>%d/100</>', $eval->getScoreOutOf100()),
                DealCategory::Bad => sprintf('<fg=yellow>%d/100</>', $eval->getScoreOutOf100()),
                DealCategory::Poor => sprintf('<fg=red>%d/100</>', $eval->getScoreOutOf100()),
            };

            $row = [
                $scoreBadge,
            ];

            if ($includeCategoryColumn) {
                $row[] = match ($eval->getCategory()) {
                    DealCategory::MustBuy => '<bg=green;fg=black;options=bold> MUST BUY </>',
                    DealCategory::ExtremelyGood => '<bg=cyan;fg=black;options=bold> EXTREMELY GOOD </>',
                    DealCategory::Good => '<bg=blue;fg=white;options=bold> GOOD </>',
                    DealCategory::Bad => '<bg=yellow;fg=black> BAD </>',
                    DealCategory::Poor => '<bg=red;fg=white> POOR </>',
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
                DealCategory::ExtremelyGood => 'fg=black;bg=cyan;options=bold',
                DealCategory::Good => 'fg=white;bg=blue;options=bold',
                DealCategory::Bad => 'fg=black;bg=yellow;options=bold',
                DealCategory::Poor => 'fg=white;bg=red;options=bold',
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

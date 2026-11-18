<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\ListingEvaluation;
use App\Entity\SearchRequest;
use App\Enum\DealCategory;
use App\Repository\CronTaskRepository;
use App\Repository\ListingEvaluationRepository;
use App\Repository\ListingRepository;
use App\Repository\SearchRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class DashboardAnalyticsService
{
    public function __construct(
        private readonly SearchRequestRepository $searchRequestRepository,
        private readonly ListingRepository $listingRepository,
        private readonly ListingEvaluationRepository $evaluationRepository,
        private readonly CronTaskRepository $cronTaskRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(env: 'AI_MODEL')]
        private readonly string $aiModel = 'lfm2.5:8b',
        #[Autowire(env: 'AI_PROVIDER')]
        private readonly string $aiProvider = 'ollama',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobalStats(): array
    {
        $totalSearches = $this->searchRequestRepository->count([]);
        $activeCrons = $this->cronTaskRepository->count([]);
        $totalListings = $this->listingRepository->count([]);
        $totalEvaluated = $this->evaluationRepository->count([]);
        $mustBuyTotal = $this->evaluationRepository->count(['category' => DealCategory::MustBuy]);
        $goodTotal = $this->evaluationRepository->count(['category' => DealCategory::Good]);

        $queuedMessages = 0;
        try {
            $queuedMessages = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages');
        } catch (\Throwable) {
            $queuedMessages = 0;
        }

        return [
            'total_searches' => $totalSearches,
            'active_crons' => $activeCrons,
            'total_listings' => $totalListings,
            'total_evaluated' => $totalEvaluated,
            'must_buy_total' => $mustBuyTotal,
            'good_total' => $goodTotal,
            'queued_messages' => $queuedMessages,
            'ai_model' => $this->aiModel,
            'ai_provider' => $this->aiProvider,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSearchesSummaries(): array
    {
        $searches = $this->searchRequestRepository->findAllOrderedByNewest();
        $summaries = [];

        foreach ($searches as $search) {
            $totalAds = $this->listingRepository->count(['searchRequest' => $search]);
            $evaluatedAds = $this->evaluationRepository->count(['searchRequest' => $search]);
            $mustBuyCount = $this->evaluationRepository->count([
                'searchRequest' => $search,
                'category' => DealCategory::MustBuy,
            ]);
            $goodCount = $this->evaluationRepository->count([
                'searchRequest' => $search,
                'category' => DealCategory::Good,
            ]);

            $summaries[] = [
                'entity' => $search,
                'total_ads' => $totalAds,
                'evaluated_ads' => $evaluatedAds,
                'must_buy_count' => $mustBuyCount,
                'good_count' => $goodCount,
                'progress_percent' => $totalAds > 0 ? min(100, (int) round(($evaluatedAds / $totalAds) * 100)) : 0,
            ];
        }

        return $summaries;
    }

    /**
     * @param list<ListingEvaluation> $allEvaluations
     *
     * @return array<string, mixed>
     */
    public function buildSearchAnalytics(SearchRequest $searchRequest, array $allEvaluations): array
    {
        $totalStoredAds = $this->listingRepository->count(['searchRequest' => $searchRequest]);
        $totalEvaluatedAds = \count($allEvaluations);
        $progressPercent = $totalStoredAds > 0
            ? min(100, (int) round(($totalEvaluatedAds / $totalStoredAds) * 100))
            : 0;

        $mustBuyCount = 0;
        $goodCount = 0;
        $passCount = 0;
        $workingCount = 0;

        $prices = [];
        $scores = [];
        $priceScores = [];
        $workingScores = [];
        $ageScores = [];
        $relevanceScores = [];

        $providerStats = [];
        $subTopicStats = [];
        $scatterPoints = [];
        $bestDeal = null;

        foreach ($searchRequest->getEnabledProviders() as $pName) {
            $key = strtolower($pName);
            $providerStats[$key] = [
                'name' => strtoupper($pName),
                'stored_count' => $this->listingRepository->count([
                    'searchRequest' => $searchRequest,
                    'provider' => $key,
                ]),
                'evaluated_count' => 0,
                'must_buy_count' => 0,
                'good_count' => 0,
                'score_sum' => 0,
                'price_sum' => 0.0,
                'avg_score' => 0,
                'avg_price' => 0.0,
            ];
        }

        foreach ($searchRequest->getGeneratedSubTopics() as $tag) {
            $subTopicStats[$tag] = [
                'tag' => $tag,
                'stored_count' => $this->listingRepository->count([
                    'searchRequest' => $searchRequest,
                    'searchTopicUsed' => $tag,
                ]),
                'evaluated_count' => 0,
                'must_buy_count' => 0,
                'good_count' => 0,
                'score_sum' => 0,
                'avg_score' => 0,
                'max_score' => 0,
            ];
        }

        foreach ($allEvaluations as $eval) {
            $listing = $eval->getListing();
            $score = $eval->getScoreOutOf100();
            $price = $listing->getPrice();
            $providerKey = strtolower($listing->getProvider());
            $tag = $listing->getSearchTopicUsed();

            $scores[] = $score;
            $prices[] = $price;
            $priceScores[] = $eval->getPriceScore();
            $workingScores[] = $eval->getWorkingConditionScore();
            $ageScores[] = $eval->getProductAgeScore();
            $relevanceScores[] = $eval->getRelevanceScore();

            if (true === $eval->isWorking()) {
                ++$workingCount;
            }

            match ($eval->getCategory()) {
                DealCategory::MustBuy => ++$mustBuyCount,
                DealCategory::Good => ++$goodCount,
                DealCategory::Pass => ++$passCount,
            };

            if (null === $bestDeal || $score > $bestDeal->getScoreOutOf100() || ($score === $bestDeal->getScoreOutOf100() && $price < $bestDeal->getListing()->getPrice())) {
                $bestDeal = $eval;
            }

            if (!isset($providerStats[$providerKey])) {
                $providerStats[$providerKey] = [
                    'name' => strtoupper($providerKey),
                    'stored_count' => $this->listingRepository->count([
                        'searchRequest' => $searchRequest,
                        'provider' => $providerKey,
                    ]),
                    'evaluated_count' => 0,
                    'must_buy_count' => 0,
                    'good_count' => 0,
                    'score_sum' => 0,
                    'price_sum' => 0.0,
                    'avg_score' => 0,
                    'avg_price' => 0.0,
                ];
            }

            ++$providerStats[$providerKey]['evaluated_count'];
            $providerStats[$providerKey]['score_sum'] += $score;
            $providerStats[$providerKey]['price_sum'] += $price;
            if (DealCategory::MustBuy === $eval->getCategory()) {
                ++$providerStats[$providerKey]['must_buy_count'];
            } elseif (DealCategory::Good === $eval->getCategory()) {
                ++$providerStats[$providerKey]['good_count'];
            }

            if (!isset($subTopicStats[$tag])) {
                $subTopicStats[$tag] = [
                    'tag' => $tag,
                    'stored_count' => $this->listingRepository->count([
                        'searchRequest' => $searchRequest,
                        'searchTopicUsed' => $tag,
                    ]),
                    'evaluated_count' => 0,
                    'must_buy_count' => 0,
                    'good_count' => 0,
                    'score_sum' => 0,
                    'avg_score' => 0,
                    'max_score' => 0,
                ];
            }

            ++$subTopicStats[$tag]['evaluated_count'];
            $subTopicStats[$tag]['score_sum'] += $score;
            $subTopicStats[$tag]['max_score'] = max($subTopicStats[$tag]['max_score'], $score);
            if (DealCategory::MustBuy === $eval->getCategory()) {
                ++$subTopicStats[$tag]['must_buy_count'];
            } elseif (DealCategory::Good === $eval->getCategory()) {
                ++$subTopicStats[$tag]['good_count'];
            }

            if (\count($scatterPoints) < 120) {
                $scatterPoints[] = [
                    'x' => round($price, 2),
                    'y' => $score,
                    'title' => mb_strimwidth($listing->getTitle(), 0, 45, '...'),
                    'provider' => strtoupper($listing->getProvider()),
                    'category' => $eval->getCategory()->value,
                    'url' => $listing->getUrl(),
                ];
            }
        }

        foreach ($providerStats as $k => $pStat) {
            if ($pStat['evaluated_count'] > 0) {
                $providerStats[$k]['avg_score'] = (int) round($pStat['score_sum'] / $pStat['evaluated_count']);
                $providerStats[$k]['avg_price'] = round($pStat['price_sum'] / $pStat['evaluated_count'], 2);
            }
        }

        foreach ($subTopicStats as $t => $tStat) {
            if ($tStat['evaluated_count'] > 0) {
                $subTopicStats[$t]['avg_score'] = (int) round($tStat['score_sum'] / $tStat['evaluated_count']);
            }
        }

        $avgPrice = [] !== $prices ? round(array_sum($prices) / \count($prices), 2) : 0.0;
        $minPrice = [] !== $prices ? round((float) min($prices), 2) : 0.0;
        $maxPrice = [] !== $prices ? round((float) max($prices), 2) : 0.0;
        $avgScore = [] !== $scores ? (int) round(array_sum($scores) / \count($scores)) : 0;

        $targetPrice = $searchRequest->getTargetPrice();
        $bestSavingsPercent = 0;
        if (null !== $bestDeal && $targetPrice > 0) {
            $bestSavingsPercent = (int) round((($targetPrice - $bestDeal->getListing()->getPrice()) / $targetPrice) * 100);
        }

        return [
            'total_stored_ads' => $totalStoredAds,
            'total_evaluated_ads' => $totalEvaluatedAds,
            'uncomputed_ads' => max(0, $totalStoredAds - $totalEvaluatedAds),
            'progress_percent' => $progressPercent,
            'must_buy_count' => $mustBuyCount,
            'good_count' => $goodCount,
            'pass_count' => $passCount,
            'working_count' => $workingCount,
            'avg_score' => $avgScore,
            'avg_price' => $avgPrice,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'best_deal' => $bestDeal,
            'best_savings_percent' => $bestSavingsPercent,
            'sub_score_averages' => [
                'price' => [] !== $priceScores ? (int) round(array_sum($priceScores) / \count($priceScores)) : 0,
                'working' => [] !== $workingScores ? (int) round(array_sum($workingScores) / \count($workingScores)) : 0,
                'age' => [] !== $ageScores ? (int) round(array_sum($ageScores) / \count($ageScores)) : 0,
                'relevance' => [] !== $relevanceScores ? (int) round(array_sum($relevanceScores) / \count($relevanceScores)) : 0,
            ],
            'provider_stats' => array_values($providerStats),
            'sub_topic_stats' => array_values($subTopicStats),
            'scatter_points' => $scatterPoints,
        ];
    }
}

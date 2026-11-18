<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Dto\Ai\ListingEvaluationResultDto;
use App\Entity\Listing;
use App\Entity\SearchRequest;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ListingEvaluatorAgent
{
    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function evaluate(SearchRequest $searchRequest, Listing $listing): ListingEvaluationResultDto
    {
        $base64Images = [];
        if ($searchRequest->isEnableImages() && [] !== $listing->getImageUrls()) {
            $base64Images = $this->downloadImagesAsBase64(\array_slice($listing->getImageUrls(), 0, 2));
        }

        $unifiedSpec = trim($searchRequest->getTopic());
        if (null !== $searchRequest->getUserRequirements() && '' !== trim($searchRequest->getUserRequirements())) {
            $unifiedSpec .= "\nAdditional requirements: ".trim($searchRequest->getUserRequirements());
        }

        $systemPrompt = <<<'PROMPT'
You are a strict, skeptical technical appraiser for second-hand and refurbished marketplaces.
Your job is to evaluate a marketplace listing against the buyer's Product Specification and Target Budget, and spread scores accurately across the entire 0 to 100 scale so that only rare, exact matches achieve top scores.

EVALUATION RULES (APPLIES TO ANY PRODUCT CATEGORY IN THE WORLD):
1. MANDATORY SPECIFICATION & SEMANTIC EQUIVALENCE CHECK:
   - Extract the core product type and all mandatory specifications from the buyer's Product Specification.
   - Apply SEMANTIC AND TECHNICAL EQUIVALENCE: sellers often write specifications using slightly different formatting, spacing, abbreviations, or in French vs. English. Also, if a buyer asks for "at least X" (capacity, ports, size, power, etc.) and the listing offers more than X, or if the listing's exact model number inherently possesses the requested specification, treat that requirement as FULLY SATISFIED.
   - WRONG PRODUCT OR ACCESSORY ONLY: If the listing is NOT the main product requested (for example, it is just an empty box, a protective case, a power brick, a cable, a consumable/cartridge, or an unrelated product category), you MUST score `relevance_score` <= 10 and overall `score` <= 10 (POOR).
   - MISSING MANDATORY SPECIFICATION: If the listing is the right product category but clearly lacks or contradicts a mandatory feature or model requested by the buyer, you MUST cap `relevance_score` <= 29 and overall `score` <= 29 (POOR).
2. WORKING CONDITION CHECK:
   - Detect broken, defective, "HS", "pour pièces", "en panne", "ne s'allume plus", or heavily damaged items. If non-working, set `is_working` to false, `working_condition_score` <= 15, and overall `score` <= 15.
3. PRICE vs. TARGET BUDGET CHECK:
   - The buyer's Target Price is their reference budget.
   - Listings priced below or at the target price with great specs score high on `price_score` (85-100).
   - Listings priced >15% above target price cannot exceed overall `score` 49.
   - Listings priced above target price cannot be MUST BUY (max overall `score` 85).
4. 5-TIER SCORE SCALE:
   - 90-100 (MUST BUY - RARE): Exact product & all mandatory specs verified + functional + price at or below target price.
   - 70-89 (EXTREMELY GOOD): Matches all key specs, functional, good price around or below target.
   - 50-69 (GOOD): Matches the main product and most specs, acceptable price or minor uncertainty/wear.
   - 30-49 (BAD): Overpriced compared to target, missing secondary features, or poor condition.
   - 0-29 (POOR): Wrong item/accessory only (0-10), broken/HS (0-15), or missing a mandatory specification from the buyer's request (10-29).

Respond ONLY with a valid JSON object matching this exact schema:
{
  "score": 0-100,
  "price_score": 0-100,
  "working_condition_score": 0-100,
  "product_age_score": 0-100,
  "relevance_score": 0-100,
  "is_working": true|false|null,
  "estimated_age": "short string estimate or null",
  "reasoning": "concise technical appraisal explaining how the listing matches or fails each specification in the buyer's request and whether the price is a strong deal"
}
PROMPT;

        $userPrompt = sprintf(
            "Buyer Product Specification:\n%s\n\nBuyer Target Price (Budget): %.2f EUR\n\nListing Provider: %s\nSearch Query Used: %s\nListing Title: %s\nListing Price: %.2f %s\nListing Condition State: %s\nListing Description: %s\nImages Attached: %d",
            $unifiedSpec,
            $searchRequest->getTargetPrice(),
            $listing->getProvider(),
            $listing->getSearchTopicUsed(),
            $listing->getTitle(),
            $listing->getPrice(),
            $listing->getCurrency(),
            $listing->getConditionState() ?? 'Unspecified',
            $listing->getDescription() ?? 'No description provided.',
            \count($base64Images),
        );

        try {
            $dto = $this->llmClient->generateStructured(
                $systemPrompt,
                $userPrompt,
                ListingEvaluationResultDto::class,
                $base64Images,
            );

            $calibrated = $this->enforceStrictCalibration($searchRequest, $listing, $dto);

            return $calibrated->withImageAnalyzed([] !== $base64Images);
        } catch (\Throwable $e) {
            $this->logger->warning('AI listing evaluation failed, falling back to deterministic heuristic evaluation.', [
                'listing_id' => $listing->getExternalId(),
                'provider' => $listing->getProvider(),
                'error' => $e->getMessage(),
            ]);

            return $this->evaluateDeterministically($searchRequest, $listing, [] !== $base64Images);
        }
    }

    /**
     * Universal, domain-agnostic calibration rules that ensure consistency between
     * sub-scores, working condition, price ratio, and overall 5-tier score.
     */
    private function enforceStrictCalibration(
        SearchRequest $searchRequest,
        Listing $listing,
        ListingEvaluationResultDto $dto,
    ): ListingEvaluationResultDto {
        $combinedText = mb_strtolower(implode(' ', array_filter([
            $listing->getTitle(),
            $listing->getDescription(),
            $listing->getConditionState(),
        ])));

        $score = $dto->score;
        $priceScore = $dto->priceScore;
        $workingScore = $dto->workingConditionScore;
        $ageScore = $dto->productAgeScore;
        $relevanceScore = $dto->relevanceScore;
        $isWorking = $dto->isWorking;

        // 1. Universal defective / broken item detection
        if (false === $isWorking || 1 === preg_match('/\b(hs|pour pieces|pour pi[eè]ces|en panne|ne s\'allume plus|hors service|defectueux|d[eé]fectueux|for parts|not working|broken)\b/u', $combinedText)) {
            $isWorking = false;
            $workingScore = min($workingScore, 10);
            $score = min($score, 15);
        }

        // 2. Respect low relevance scores (wrong product type / missing mandatory specs)
        if ($relevanceScore <= 15) {
            $score = min($score, 10);
        } elseif ($relevanceScore <= 30) {
            $score = min($score, 29);
        } elseif ($relevanceScore <= 50) {
            $score = min($score, 49);
        }

        // 3. Universal price discipline against buyer's target budget
        $targetPrice = max(1.0, $searchRequest->getTargetPrice());
        $priceRatio = $listing->getPrice() / $targetPrice;

        if ($priceRatio > 1.35) {
            $priceScore = min($priceScore, 20);
            $score = min($score, 35);
        } elseif ($priceRatio > 1.15) {
            $priceScore = min($priceScore, 45);
            $score = min($score, 49);
        } elseif ($priceRatio > 1.0) {
            $priceScore = min($priceScore, 70);
            $score = min($score, 85);
        }

        // 4. Weighted composite ceiling (Relevance 45%, Price 25%, Working 20%, Age 10%)
        $weightedCeiling = (int) round(
            ($relevanceScore * 0.45)
            + ($priceScore * 0.25)
            + ($workingScore * 0.20)
            + ($ageScore * 0.10)
        );
        $score = min($score, max($weightedCeiling, $relevanceScore >= 90 && $priceRatio <= 1.0 && false !== $isWorking ? 90 : $weightedCeiling));

        // 5. MUST BUY (90-100) requires strong relevance (>= 88), functional state, and price <= targetPrice
        if ($score >= 90 && ($relevanceScore < 88 || $priceRatio > 1.0 || false === $isWorking)) {
            $score = min($score, 88);
        }

        return new ListingEvaluationResultDto(
            score: max(0, min(100, $score)),
            priceScore: max(0, min(100, $priceScore)),
            workingConditionScore: max(0, min(100, $workingScore)),
            productAgeScore: max(0, min(100, $ageScore)),
            relevanceScore: max(0, min(100, $relevanceScore)),
            isWorking: $isWorking,
            estimatedAge: $dto->estimatedAge,
            reasoning: trim($dto->reasoning),
            imageAnalyzed: $dto->imageAnalyzed,
        );
    }

    /**
     * @param list<string> $imageUrls
     *
     * @return list<string>
     */
    private function downloadImagesAsBase64(array $imageUrls): array
    {
        $encoded = [];

        foreach ($imageUrls as $url) {
            if ('' === trim($url)) {
                continue;
            }

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 5,
                ]);

                if (200 === $response->getStatusCode()) {
                    $content = $response->getContent(false);
                    if ('' !== $content) {
                        $encoded[] = base64_encode($content);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Could not download listing image for AI analysis.', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $encoded;
    }

    private function evaluateDeterministically(
        SearchRequest $searchRequest,
        Listing $listing,
        bool $imageAnalyzed,
    ): ListingEvaluationResultDto {
        $targetPrice = max(1.0, $searchRequest->getTargetPrice());
        $listingPrice = max(0.0, $listing->getPrice());
        $priceRatio = $listingPrice / $targetPrice;

        if ($priceRatio <= 0.75) {
            $priceScore = 95;
        } elseif ($priceRatio <= 0.90) {
            $priceScore = 88;
        } elseif ($priceRatio <= 1.00) {
            $priceScore = 80;
        } elseif ($priceRatio <= 1.15) {
            $priceScore = 55;
        } elseif ($priceRatio <= 1.35) {
            $priceScore = 38;
        } else {
            $priceScore = 15;
        }

        $combinedText = mb_strtolower(implode(' ', array_filter([
            $listing->getTitle(),
            $listing->getDescription(),
            $listing->getConditionState(),
        ])));

        $isWorking = true;
        $workingConditionScore = 78;

        if (1 === preg_match('/\b(hs|pour pieces|pour pi[eè]ces|en panne|ne s\'allume plus|ecran casse|hors service)\b/u', $combinedText)) {
            $isWorking = false;
            $workingConditionScore = 10;
        } elseif (1 === preg_match('/\b(neuf|comme neuf|parfait|parfait etat|reconditionne grade a|excellent)\b/u', $combinedText)) {
            $workingConditionScore = 95;
        } elseif (1 === preg_match('/\b(tres bon|tr[eè]s bon|bon etat|bon [eé]tat|100% fonctionnel)\b/u', $combinedText)) {
            $workingConditionScore = 85;
        } elseif (1 === preg_match('/\b(etat satisfaisant|usure|rayures|fissure)\b/u', $combinedText)) {
            $workingConditionScore = 55;
        }

        $estimatedAge = '2-3 ans (estime)';
        $productAgeScore = 75;
        if (1 === preg_match('/\b(2025|2026|neuf|sous blister|garantie)\b/u', $combinedText)) {
            $estimatedAge = 'Moins de 1 an';
            $productAgeScore = 92;
        } elseif (1 === preg_match('/\b(2023|2024|recent)\b/u', $combinedText)) {
            $estimatedAge = '1-2 ans';
            $productAgeScore = 84;
        } elseif (1 === preg_match('/\b(2018|2019|2020|ancien|vintage)\b/u', $combinedText)) {
            $estimatedAge = '4+ ans';
            $productAgeScore = 52;
        }

        $stopWords = [
            'with', 'without', 'least', 'most', 'more', 'less', 'than', 'from', 'that', 'this', 'have', 'for', 'and', 'the',
            'avec', 'sans', 'moins', 'plus', 'dans', 'pour', 'une', 'des', 'les', 'aux', 'sur',
        ];
        $topicWords = array_values(array_filter(
            preg_split('/[\s,\-\/]+/', mb_strtolower($searchRequest->getTopic())) ?: [],
            static fn (string $w): bool => mb_strlen($w) >= 2 && !in_array($w, $stopWords, true),
        ));

        $matchedWords = 0;
        foreach ($topicWords as $word) {
            if (str_contains($combinedText, $word)) {
                ++$matchedWords;
            }
        }

        $topicMatchRatio = [] !== $topicWords ? $matchedWords / \count($topicWords) : 1.0;
        $relevanceScore = (int) round(10 + ($topicMatchRatio * 85));

        $overallScore = (int) round(
            ($relevanceScore * 0.45)
            + ($priceScore * 0.25)
            + ($workingConditionScore * 0.20)
            + ($productAgeScore * 0.10)
        );

        $reasoning = sprintf(
            'Price %.2f %s vs target %.2f EUR (price score %d/100). Condition %d/100 (%s). Specification relevance %d/100.',
            $listingPrice,
            $listing->getCurrency(),
            $targetPrice,
            $priceScore,
            $workingConditionScore,
            $isWorking ? 'functional' : 'defective/HS',
            $relevanceScore,
        );

        $rawDto = new ListingEvaluationResultDto(
            score: max(0, min(100, $overallScore)),
            priceScore: max(0, min(100, $priceScore)),
            workingConditionScore: max(0, min(100, $workingConditionScore)),
            productAgeScore: max(0, min(100, $productAgeScore)),
            relevanceScore: max(0, min(100, $relevanceScore)),
            isWorking: $isWorking,
            estimatedAge: $estimatedAge,
            reasoning: $reasoning,
            imageAnalyzed: $imageAnalyzed,
        );

        return $this->enforceStrictCalibration($searchRequest, $listing, $rawDto);
    }
}

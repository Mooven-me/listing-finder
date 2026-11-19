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
        private readonly ?SearxngProductSpecService $productSpecService = null,
    ) {
    }

    public function evaluate(SearchRequest $searchRequest, Listing $listing): ListingEvaluationResultDto
    {
        $base64Images = [];
        if ($searchRequest->isEnableImages() && [] !== $listing->getImageUrls() && $this->llmClient->supportsVision()) {
            $base64Images = $this->downloadImagesAsBase64(\array_slice($listing->getImageUrls(), 0, 2));
        }

        $externalSpecs = $this->productSpecService?->lookupSpecsForTitle($listing->getTitle());

        $unifiedSpec = trim($searchRequest->getTopic());
        if (null !== $searchRequest->getUserRequirements() && '' !== trim($searchRequest->getUserRequirements())) {
            $unifiedSpec .= "\nAdditional requirements: ".trim($searchRequest->getUserRequirements());
        }

        $cleanDescription = trim((string) ($listing->getDescription() ?? ''));
        if ('' === $cleanDescription) {
            $cleanDescription = 'No seller description provided.';
        }

        $systemPrompt = <<<'PROMPT'
You are a strict, factual, evidence-only second-hand marketplace listing appraiser.
Compare ONLY the Listing (Title, Description, Condition, External Product Reference) against EVERY requirement in the Buyer Product Specification.

CRITICAL RULES (APPLIES TO ANY PRODUCT CATEGORY IN THE WORLD):
1. Write "reasoning" FIRST:
   - Step 1: Identify what item the listing is ACTUALLY selling (main product vs. an accessory, cable, case, box, manual, or unrelated category).
   - Step 2: Check EACH mandatory buyer requirement one by one ONLY against facts explicitly written in the Listing or External Product Reference (SearXNG). NEVER invent, assume, or copy unstated specs from the Buyer Product Specification!
   - Treat true unit/language equivalents as matching (e.g., 1Gbps = 1000Mbps = Gigabit; French vs. English terms; a higher quantity satisfying "at least X").
   - NEVER confuse distinct standards or upgraded tiers (e.g., a base standard does NOT satisfy a "+" or upgraded standard).
2. Set "is_wrong_product_or_accessory" = true if the listing is a different category or just an accessory/cable/box/part instead of the core product requested (relevance_score <= 10, score <= 10).
3. Put ONLY genuinely missing, unverified, or contradicted BUYER requirements in "missing_or_unverified_specs". If ALL mandatory buyer requirements are clearly stated in the listing or External Reference, "missing_or_unverified_specs" MUST be an empty array [].
4. Standard marketplace condition labels ("État neuf", "Très bon état", "Bon état") mean is_working = true and working_condition_score 85-100 unless defects/HS/"pour pièces"/"en panne" are stated.
5. 5-Tier Score Scale:
   - 90-100 (MUST BUY - RARE): ALL mandatory buyer requirements explicitly verified (missing_or_unverified_specs = []), functional, price <= target.
   - 70-89 (EXTREMELY GOOD): ALL mandatory buyer requirements explicitly verified (missing_or_unverified_specs = []), functional, strong price around or below target.
   - 50-69 (GOOD): Core product and primary specs verified, minor uncertainty on secondary details or slightly higher price.
   - 30-49 (BAD): Overpriced (>15% above target), poor condition, OR 1 mandatory buyer requirement unverified.
   - 0-29 (POOR): Wrong product/accessory (0-10), broken/HS (0-15), contradicted mandatory spec (10-25), OR 2+ mandatory buyer specs missing/unverified (10-29).

Respond ONLY with a valid JSON object matching this exact key order:
{
  "reasoning": "step-by-step factual check of product type and each buyer requirement against the listing and SearXNG reference",
  "is_wrong_product_or_accessory": false,
  "matched_specs": ["only buyer requirements explicitly evidenced"],
  "missing_or_unverified_specs": ["only mandatory buyer requirements contradicted or not stated anywhere"],
  "is_working": true,
  "estimated_age": "short string estimate or 'unknown'",
  "relevance_score": 0-100,
  "price_score": 0-100,
  "working_condition_score": 0-100,
  "product_age_score": 0-100,
  "score": 0-100
}
PROMPT;

        $externalRefBlock = null !== $externalSpecs
            ? sprintf("\n\nVerified External Product Reference (SearXNG):\n%s", $externalSpecs)
            : "\n\nVerified External Product Reference (SearXNG): None available (rely ONLY on the listing text above; do NOT guess unstated model specs).";

        $userPrompt = sprintf(
            "Buyer Product Specification:\n%s\n\nBuyer Target Price (Budget): %.2f EUR\n\nListing Provider: %s\nSearch Query Used: %s\nListing Title: %s\nListing Price: %.2f %s\nListing Condition State: %s\nListing Description:\n%s%s\n\nImages Attached: %d",
            $unifiedSpec,
            $searchRequest->getTargetPrice(),
            $listing->getProvider(),
            $listing->getSearchTopicUsed(),
            $listing->getTitle(),
            $listing->getPrice(),
            $listing->getCurrency(),
            $listing->getConditionState() ?? 'Unspecified',
            $cleanDescription,
            $externalRefBlock,
            \count($base64Images),
        );

        try {
            $dto = $this->llmClient->generateStructured(
                $systemPrompt,
                $userPrompt,
                ListingEvaluationResultDto::class,
                $base64Images,
            );

            return $this->enforceStrictCalibration($searchRequest, $listing, $dto, $externalSpecs);
        } catch (\Throwable $e) {
            $this->logger->warning('AI listing evaluation failed, falling back to deterministic heuristic evaluation.', [
                'listing_id' => $listing->getExternalId(),
                'provider' => $listing->getProvider(),
                'error' => $e->getMessage(),
            ]);

            return $this->evaluateDeterministically($searchRequest, $listing, false);
        }
    }

    /**
     * Universal, domain-agnostic calibration rules that enforce strict consistency between
     * verified evidence, missing specs, working condition, price ratio, and overall 5-tier score.
     */
    private function enforceStrictCalibration(
        SearchRequest $searchRequest,
        Listing $listing,
        ListingEvaluationResultDto $dto,
        ?string $externalSpecs = null,
    ): ListingEvaluationResultDto {
        $combinedText = mb_strtolower(implode(' ', array_filter([
            $listing->getTitle(),
            $listing->getDescription(),
            $listing->getConditionState(),
        ])));
        $evidenceText = mb_strtolower(implode(' ', array_filter([
            $listing->getTitle(),
            $listing->getDescription(),
            $listing->getConditionState(),
            $externalSpecs,
        ])));

        $score = $dto->score;
        $priceScore = $dto->priceScore;
        $workingScore = $dto->workingConditionScore;
        $ageScore = $dto->productAgeScore;
        $relevanceScore = $dto->relevanceScore;
        $isWorking = $dto->isWorking;
        $reasoning = trim($dto->reasoning);

        // 1. Wrong product type or accessory only
        if ($dto->isWrongProductOrAccessory) {
            $relevanceScore = min($relevanceScore, 10);
            $score = min($score, 10);
        }

        // 2. Universal defective / broken item detection
        if (false === $isWorking || 1 === preg_match('/\b(hs|pour pieces|pour pi[eè]ces|en panne|ne s\'allume plus|hors service|defectueux|d[eé]fectueux|for parts|not working|broken)\b/u', $combinedText)) {
            $isWorking = false;
            $workingScore = min($workingScore, 10);
            $score = min($score, 15);
        }

        // 3. Collect LLM-reported missing specs + domain-agnostic exact-token evidence check
        $missingSpecs = array_values(array_filter(
            array_map(static fn (string $s): string => trim($s), $dto->missingOrUnverifiedSpecs),
            static fn (string $s): bool => '' !== $s && !\in_array(strtolower($s), ['none', 'n/a', 'null', 'nothing', 'aucun', 'aucune'], true),
        ));

        $unverifiedExactTokens = $this->findUnverifiedExactBuyerTokens($searchRequest, $evidenceText);
        foreach ($unverifiedExactTokens as $missingToken) {
            $alreadyMentioned = false;
            foreach ($missingSpecs as $existing) {
                if (str_contains(mb_strtolower($existing), mb_strtolower($missingToken))) {
                    $alreadyMentioned = true;
                    break;
                }
            }
            if (!$alreadyMentioned) {
                $missingSpecs[] = sprintf('exact requirement "%s" not found in listing or reference', $missingToken);
            }
        }

        if ([] !== $unverifiedExactTokens || \count($missingSpecs) >= 2) {
            $relevanceScore = min($relevanceScore, 29);
            $score = min($score, 29);
        } elseif (1 === \count($missingSpecs)) {
            $relevanceScore = min($relevanceScore, 45);
            $score = min($score, 45);
        }

        // 4. Respect low relevance scores (wrong product type / missing mandatory specs)
        if ($relevanceScore <= 15) {
            $score = min($score, 10);
        } elseif ($relevanceScore <= 30) {
            $score = min($score, 29);
        } elseif ($relevanceScore <= 50) {
            $score = min($score, 49);
        }

        // 5. Universal price discipline against buyer's target budget
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

        // 6. Weighted composite ceiling (Relevance 45%, Price 25%, Working 20%, Age 10%)
        $weightedCeiling = (int) round(
            ($relevanceScore * 0.45)
            + ($priceScore * 0.25)
            + ($workingScore * 0.20)
            + ($ageScore * 0.10)
        );
        $score = min($score, max($weightedCeiling, [] === $missingSpecs && $relevanceScore >= 90 && $priceRatio <= 1.0 && false !== $isWorking ? 90 : $weightedCeiling));

        // 7. MUST BUY (90-100) requires strong relevance (>= 88), zero missing specs, functional state, and price <= targetPrice
        if ($score >= 90 && ([] !== $missingSpecs || $relevanceScore < 88 || $priceRatio > 1.0 || false === $isWorking)) {
            $score = min($score, 88);
        }

        if ([] !== $missingSpecs && !str_contains(mb_strtolower($reasoning), 'missing/unverified:')) {
            $reasoning = trim($reasoning.' Missing/unverified: '.implode(', ', \array_slice($missingSpecs, 0, 5)).'.');
        }

        return new ListingEvaluationResultDto(
            score: max(0, min(100, $score)),
            priceScore: max(0, min(100, $priceScore)),
            workingConditionScore: max(0, min(100, $workingScore)),
            productAgeScore: max(0, min(100, $ageScore)),
            relevanceScore: max(0, min(100, $relevanceScore)),
            isWorking: $isWorking,
            estimatedAge: $dto->estimatedAge,
            reasoning: $reasoning,
            matchedSpecs: $dto->matchedSpecs,
            missingOrUnverifiedSpecs: $missingSpecs,
            isWrongProductOrAccessory: $dto->isWrongProductOrAccessory,
            imageAnalyzed: $dto->imageAnalyzed,
        );
    }

    /**
     * Domain-agnostic check for high-precision buyer tokens (symbol-bearing standards like "sfp+",
     * "poe+", "75%", or explicit alphanumeric model codes like "ax380", "rtx4070") that MUST be
     * evidenced in the listing text or SearXNG reference rather than hallucinated by a small LLM.
     *
     * @return list<string>
     */
    private function findUnverifiedExactBuyerTokens(SearchRequest $searchRequest, string $evidenceTextLower): array
    {
        $spec = trim($searchRequest->getTopic().' '.($searchRequest->getUserRequirements() ?? ''));
        $rawTokens = preg_split('/[\s,\/;()\[\]]+/', $spec) ?: [];
        $unverified = [];
        $seen = [];

        foreach ($rawTokens as $rawToken) {
            $token = trim($rawToken, ".,:!?\"'`~*");
            $lower = mb_strtolower($token);
            if (mb_strlen($lower) < 3 || isset($seen[$lower])) {
                continue;
            }

            // 1. Symbol-bearing technical standards/specs (e.g. "sfp+", "poe+", "75%", "65%")
            $isSymbolSpec = 1 === preg_match('/^[a-z0-9]{2,12}[+%]$/i', $token);

            // 2. Concrete alphanumeric model codes (e.g. "ax380", "rtx4070", "fs605v3"), excluding generic units/ports
            $isGenericUnitOrPort = 1 === preg_match('/^(?:\d+(?:[.,]\d+)?(?:gbps|mbps|kbps|gb|go|mb|mo|tb|to|ghz|mhz|hz|w|kw|v|mv|a|ma|mah|wh|mm|cm|m|km|kg|g|ml|cl|l|px|mp|fps|db|rpm|x|p|k)|cat\d+[a-z]?|rj\d+|usb\d+[a-z]?|wifi\d+[a-z]?|hdmi\d+[a-z]?)$/i', $token);
            $isModelCode = !$isGenericUnitOrPort && 1 === preg_match('/^[a-z]{1,6}[-._]?\d{2,}[a-z0-9._-]*$/i', $token);

            if (!$isSymbolSpec && !$isModelCode) {
                continue;
            }

            $seen[$lower] = true;

            // Check exact boundary match (so "sfp" does NOT falsely match "sfp+")
            $escaped = preg_quote($lower, '/');
            $pattern = '/(?<![a-z0-9])'.$escaped.'(?![a-z0-9+])/u';
            if (1 !== preg_match($pattern, $evidenceTextLower)) {
                $unverified[] = $token;
            }
        }

        return $unverified;
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
            static fn (string $w): bool => mb_strlen($w) >= 2 && !\in_array($w, $stopWords, true),
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

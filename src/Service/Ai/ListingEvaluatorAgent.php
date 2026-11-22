<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Dto\Ai\ListingEvaluationResultDto;
use App\Dto\Ai\ProductTypeGatekeeperResultDto;
use App\Entity\Listing;
use App\Entity\SearchRequest;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ListingEvaluatorAgent
{
    /**
     * Universal, domain-agnostic accessory / component / non-main-item keywords (EN/FR/ES/DE).
     * Never product-category specific — applies equally to electronics, cameras, tools, appliances, etc.
     *
     * @var list<string>
     */
    private const UNIVERSAL_ACCESSORY_INDICATORS = [
        'accessory', 'accessoire', 'accesorio', 'zubehör', 'zubehor',
        'module', 'módulo', 'modulo', 'modul',
        'adapter', 'adaptateur', 'adaptador',
        'converter', 'convertisseur', 'convertidor',
        'cable', 'câble', 'kabel',
        'charger', 'chargeur', 'cargador', 'ladegerät',
        'empty box', 'boîte vide', 'boite vide', 'caja vacía',
        'manual', 'manuel', 'notice',
        'spare part', 'pièce détachée', 'piece detachee', 'replacement part',
        'protective case', 'coque', 'housse', 'étui', 'etui',
        'bracket', 'mount', 'support mural',
    ];

    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?SearxngProductSpecService $productSpecService = null,
    ) {
    }

    /**
     * Stage-1 Fast Core-Product Gatekeeper (runs BEFORE detail-page HTTP fetch and BEFORE SearXNG).
     *
     * Returns a POOR (<= 10) ListingEvaluationResultDto immediately when the listing title/snippet
     * clearly sells a different item or an accessory/module/cable/part instead of the core product
     * requested by the buyer. Returns null when the listing passes Stage 1 and should proceed to
     * full detail enrichment + SearXNG + Stage-2 scoring.
     */
    public function checkCoreProductGatekeeper(SearchRequest $searchRequest, Listing $listing): ?ListingEvaluationResultDto
    {
        $unifiedSpec = $this->buildUnifiedSpec($searchRequest);

        // 1. Fast zero-LLM universal accessory check when the listing's own title explicitly opens with
        //    an accessory/module/cable/adapter noun that the buyer did NOT ask for.
        $titleAccessoryHit = $this->detectUnrequestedAccessoryInText($unifiedSpec, $listing->getTitle());
        if (null !== $titleAccessoryHit) {
            return $this->buildRejectedProductDto(
                $searchRequest,
                $listing,
                sprintf(
                    'Rejected in Stage-1 gatekeeper: listing title "%s" explicitly sells an accessory/component ("%s") rather than the main product requested by the buyer.',
                    $listing->getTitle(),
                    $titleAccessoryHit,
                ),
                $titleAccessoryHit,
            );
        }

        // 2. Fast focused LLM yes/no gatekeeper (~40 output tokens) to catch non-obvious wrong categories
        $systemPrompt = <<<'PROMPT'
You are a strict product-type gatekeeper for second-hand classified ads.
Compare ONLY the CORE PRODUCT TYPE the buyer wants against the ACTUAL ITEM being sold in the listing.

RULES (APPLIES TO ANY PRODUCT CATEGORY):
- Ignore price and detailed technical specs for now — focus ONLY on whether the listing is selling the SAME core product type, or instead selling an accessory, plug-in module, cable, adapter, spare part, empty box, manual, or unrelated category.
- If the listing sells an accessory, component, plug-in module, cable, adapter, box, or different product category when the buyer wants the main device/product, set "is_same_core_product": false and "is_accessory_or_component_only": true.
- Respond ONLY with valid JSON:
{"buyer_wants_item_type": "...", "listing_sells_item_type": "...", "is_same_core_product": true, "is_accessory_or_component_only": false, "brief_reason": "..."}
PROMPT;

        $shortDesc = trim((string) ($listing->getDescription() ?? ''));
        $userPrompt = sprintf(
            "Buyer Specification:\n%s\n\nListing Title: %s\nListing Snippet: %s",
            mb_strimwidth($unifiedSpec, 0, 500, '...'),
            $listing->getTitle(),
            '' !== $shortDesc ? mb_strimwidth($shortDesc, 0, 300, '...') : 'None yet',
        );

        try {
            /** @var ProductTypeGatekeeperResultDto $gate */
            $gate = $this->llmClient->generateStructured(
                $systemPrompt,
                $userPrompt,
                ProductTypeGatekeeperResultDto::class,
            );

            $accessoryFromClassification = $this->detectUnrequestedAccessoryInText(
                $unifiedSpec,
                $gate->listingSellsItemType,
            );

            if (!$gate->isSameCoreProduct || $gate->isAccessoryOrComponentOnly || null !== $accessoryFromClassification) {
                $reason = sprintf(
                    'Rejected in Stage-1 gatekeeper: buyer wants "%s", but listing sells "%s". %s',
                    $gate->buyerWantsItemType,
                    $gate->listingSellsItemType,
                    trim($gate->briefReason),
                );

                return $this->buildRejectedProductDto(
                    $searchRequest,
                    $listing,
                    $reason,
                    $gate->listingSellsItemType,
                    $gate->buyerWantsItemType,
                );
            }
        } catch (\Throwable $e) {
            // If Stage-1 gatekeeper fails transiently, fall through to Stage 2 rather than dropping valid listings
            $this->logger->debug('Stage-1 product-type gatekeeper skipped due to transient error.', [
                'listing_id' => $listing->getExternalId(),
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    public function evaluate(SearchRequest $searchRequest, Listing $listing, bool $runGatekeeper = true): ListingEvaluationResultDto
    {
        if ($runGatekeeper) {
            $rejected = $this->checkCoreProductGatekeeper($searchRequest, $listing);
            if (null !== $rejected) {
                return $rejected;
            }
        }

        $base64Images = [];
        if ($searchRequest->isEnableImages() && [] !== $listing->getImageUrls() && $this->llmClient->supportsVision()) {
            $base64Images = $this->downloadImagesAsBase64(\array_slice($listing->getImageUrls(), 0, 2));
        }

        $externalSpecs = $this->productSpecService?->lookupSpecsForTitle($listing->getTitle());
        $unifiedSpec = $this->buildUnifiedSpec($searchRequest);

        $cleanDescription = trim((string) ($listing->getDescription() ?? ''));
        if ('' === $cleanDescription) {
            $cleanDescription = 'No seller description provided.';
        }

        $systemPrompt = <<<'PROMPT'
You are a strict, factual, evidence-only second-hand marketplace listing appraiser.
Compare ONLY the Listing (Title, Description, Condition, External Product Reference) against EVERY requirement in the Buyer Product Specification.

CRITICAL RULES (APPLIES TO ANY PRODUCT CATEGORY IN THE WORLD):
1. Write "reasoning" FIRST:
   - Step 1: Identify what item the listing is ACTUALLY selling ("listing_actual_product_type") vs. what the buyer wants ("buyer_requested_product_type").
   - Step 2: Check EACH mandatory buyer requirement one by one ONLY against facts explicitly written in the Listing or External Product Reference (SearXNG). NEVER invent, assume, or copy unstated specs from the Buyer Product Specification!
   - If the buyer wrote alternative/conditional options (e.g. "either option A or option B", "if X then Y, otherwise Z"), satisfy ANY ONE complete valid branch.
   - Treat true unit/language equivalents as matching (e.g., 1Gbps = 1000Mbps = Gigabit; French vs. English terms; a higher quantity satisfying "at least X").
   - NEVER confuse distinct standards or upgraded tiers (e.g., a base standard does NOT satisfy a "+" or upgraded standard).
2. Set "is_wrong_product_or_accessory" = true if the listing is a different category or just an accessory/module/cable/adapter/box/part instead of the core product requested (relevance_score <= 10, score <= 10).
3. Put ONLY genuinely missing, unverified, or contradicted mandatory BUYER requirements in "missing_or_unverified_specs". If ALL mandatory requirements for a valid buyer option are clearly stated in the listing or External Reference, "missing_or_unverified_specs" MUST be [].
4. Standard marketplace condition labels ("État neuf", "Très bon état", "Bon état") mean is_working = true and working_condition_score 85-100 unless defects/HS/"pour pièces"/"en panne" are stated.
5. 5-Tier Score Scale:
   - 90-100 (MUST BUY - LEGENDARY): ALL mandatory buyer requirements explicitly verified (missing_or_unverified_specs = []), functional, price <= target, and the product is EXTREMELY GOOD. If a product is meant to go in this category, this mean this is a product to must buy instantly.
   - 70-89 (EXTREMELY GOOD): ALL mandatory buyer requirements explicitly verified (missing_or_unverified_specs = []), functional, price around or below target or the item is good to buy.
   - 50-69 (GOOD): Core product and primary specs verified, minor uncertainty on secondary details.
   - 30-49 (BAD): poor condition, OR multiple mandatory buyer requirement unverified.
   - 0-29 (POOR): Wrong product/accessory (0-10), broken/HS (0-15), contradicted mandatory spec (10-25), OR 2+ mandatory buyer specs missing/unverified (10-29).

Respond ONLY with a valid JSON object matching this exact key order:
{
  "reasoning": "step-by-step factual check of product type and each buyer requirement against the listing and SearXNG reference",
  "buyer_requested_product_type": "main product type buyer wants",
  "listing_actual_product_type": "actual item sold in listing",
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
     * verified evidence, accessory mismatch, missing specs, working condition, price ratio,
     * and overall 5-tier score.
     */
    private function enforceStrictCalibration(
        SearchRequest $searchRequest,
        Listing $listing,
        ListingEvaluationResultDto $dto,
        ?string $externalSpecs = null,
    ): ListingEvaluationResultDto {
        $unifiedSpec = $this->buildUnifiedSpec($searchRequest);
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
        $isWrongProduct = $dto->isWrongProductOrAccessory;

        // 1. Universal accessory/component mismatch consistency check
        if (!$isWrongProduct) {
            $unrequestedAccessory = $this->detectUnrequestedAccessoryInText(
                $unifiedSpec,
                $dto->listingActualProductType.' '.$listing->getTitle(),
            );
            if (null !== $unrequestedAccessory) {
                $isWrongProduct = true;
                $reasoning = trim($reasoning.sprintf(' Flagged as accessory/component mismatch ("%s").', $unrequestedAccessory));
            }
        }

        if ($isWrongProduct) {
            $relevanceScore = min($relevanceScore, 10);
            $score = min($score, 10);
        }

        // 2. Universal defective / broken item detection
        if (false === $isWorking || 1 === preg_match('/\b(hs|pour pieces|pour pi[eè]ces|en panne|ne s\'allume plus|hors service|defectueux|d[eé]fectueux|for parts|not working|broken)\b/u', $combinedText)) {
            $isWorking = false;
            $workingScore = min($workingScore, 10);
            $score = min($score, 15);
        }

        // 3. Collect LLM-reported missing specs + unconditional exact-token evidence check
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

        // 4. Respect low relevance scores
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
        $score = min($score, max($weightedCeiling, !$isWrongProduct && [] === $missingSpecs && $relevanceScore >= 90 && $priceRatio <= 1.0 && false !== $isWorking ? 90 : $weightedCeiling));

        // 7. MUST BUY (90-100) requires exact product, zero missing specs, strong relevance, functional state, and price <= targetPrice
        if ($score >= 90 && ($isWrongProduct || [] !== $missingSpecs || $relevanceScore < 88 || $priceRatio > 1.0 || false === $isWorking)) {
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
            isWrongProductOrAccessory: $isWrongProduct,
            buyerRequestedProductType: $dto->buyerRequestedProductType,
            listingActualProductType: $dto->listingActualProductType,
            imageAnalyzed: $dto->imageAnalyzed,
        );
    }

    /**
     * Checks whether a listing title or LLM item-type label explicitly identifies a universal
     * accessory/component/part that the buyer specification itself did NOT request.
     */
    private function detectUnrequestedAccessoryInText(string $buyerSpec, string $candidateText): ?string
    {
        $buyerLower = mb_strtolower($buyerSpec);
        $textLower = mb_strtolower(trim($candidateText));
        if ('' === $textLower) {
            return null;
        }

        foreach (self::UNIVERSAL_ACCESSORY_INDICATORS as $indicator) {
            $escaped = preg_quote($indicator, '/');
            $pattern = '/(?<![\p{L}\p{N}])'.$escaped.'s?(?![\p{L}\p{N}])/u';

            // Only flag when the buyer did NOT ask for this accessory/component type
            if (1 === preg_match($pattern, $buyerLower)) {
                continue;
            }

            if (1 === preg_match($pattern, $textLower)) {
                return $indicator;
            }
        }

        return null;
    }

    private function buildRejectedProductDto(
        SearchRequest $searchRequest,
        Listing $listing,
        string $reason,
        string $actualType,
        string $requestedType = '',
    ): ListingEvaluationResultDto {
        $targetPrice = max(1.0, $searchRequest->getTargetPrice());
        $priceRatio = $listing->getPrice() / $targetPrice;
        $priceScore = $priceRatio <= 1.0 ? 85 : ($priceRatio <= 1.2 ? 55 : 25);

        return new ListingEvaluationResultDto(
            score: 8,
            priceScore: $priceScore,
            workingConditionScore: 75,
            productAgeScore: 50,
            relevanceScore: 5,
            isWorking: true,
            estimatedAge: 'unknown',
            reasoning: $reason,
            matchedSpecs: [],
            missingOrUnverifiedSpecs: ['wrong product type or accessory only'],
            isWrongProductOrAccessory: true,
            buyerRequestedProductType: $requestedType,
            listingActualProductType: $actualType,
            imageAnalyzed: false,
        );
    }

    private function buildUnifiedSpec(SearchRequest $searchRequest): string
    {
        $unifiedSpec = trim($searchRequest->getTopic());
        if (null !== $searchRequest->getUserRequirements() && '' !== trim($searchRequest->getUserRequirements())) {
            // Avoid duplicating topic if userRequirements already starts with the same full text
            $req = trim($searchRequest->getUserRequirements());
            if (str_starts_with($req, $unifiedSpec)) {
                return $req;
            }
            $unifiedSpec .= "\nAdditional requirements: ".$req;
        }

        return $unifiedSpec;
    }

    /**
     * Domain-agnostic check for high-precision UNCONDITIONAL buyer tokens (symbol-bearing standards
     * like "sfp+", "poe+", "75%", or explicit alphanumeric model codes like "ax380", "rtx4070").
     *
     * Automatically skips conditional/optional specifications (e.g. "if ... otherwise ...",
     * "not mandatory", "optional", "any of those") so valid alternative branches aren't falsely penalized.
     *
     * @return list<string>
     */
    private function findUnverifiedExactBuyerTokens(SearchRequest $searchRequest, string $evidenceTextLower): array
    {
        $spec = $this->buildUnifiedSpec($searchRequest);

        // If the buyer explicitly wrote conditional / alternative branches, let the LLM evaluate the
        // branch logic rather than forcing every token in every branch to appear simultaneously.
        if (1 === preg_match('/\b(otherwise|either|not mandatory|optional|if possible|without|sinon|optionnel|ou bien|au choix|any of)\b/i', $spec)) {
            return [];
        }

        $rawTokens = preg_split('/[\s,\/;()\[\]]+/', $spec) ?: [];
        $unverified = [];
        $seen = [];

        foreach ($rawTokens as $rawToken) {
            $token = trim($rawToken, ".,:!?\"'`~*");
            $lower = mb_strtolower($token);
            if (mb_strlen($lower) < 3 || isset($seen[$lower])) {
                continue;
            }

            $isSymbolSpec = 1 === preg_match('/^[a-z0-9]{2,12}[+%]$/i', $token);

            $isGenericUnitOrPort = 1 === preg_match('/^(?:\d+(?:[.,]\d+)?(?:gbps|mbps|kbps|gb|go|mb|mo|tb|to|ghz|mhz|hz|w|kw|v|mv|a|ma|mah|wh|mm|cm|m|km|kg|g|ml|cl|l|px|mp|fps|db|rpm|x|p|k)|cat\d+[a-z]?|rj\d+|usb\d+[a-z]?|wifi\d+[a-z]?|hdmi\d+[a-z]?|gddr\d+[a-z]?|ddr\d+[a-z]?)$/i', $token);
            $isModelCode = !$isGenericUnitOrPort && 1 === preg_match('/^[a-z]{1,6}[-._]?\d{2,}[a-z0-9._-]*$/i', $token);

            if (!$isSymbolSpec && !$isModelCode) {
                continue;
            }

            $seen[$lower] = true;

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

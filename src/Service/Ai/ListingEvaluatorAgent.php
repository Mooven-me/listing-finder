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

        $systemPrompt = <<<'PROMPT'
You are a strict, skeptical technical appraiser for second-hand hardware and electronics.
Your job is to AGGRESSIVELY filter out irrelevant, incomplete, overpriced, or non-matching ads so that ONLY rare, exact matches get high scores.

STRICT SCORING RULES (MUST BE OBEYED):
1. MANDATORY SPECIFICATION CHECK:
   - Break the buyer's Search Topic into its exact required product type and technical specifications (e.g., for "sfp+ with at least 4 switch rj45 1Gb", the item MUST be a network switch, MUST have SFP+ 10Gb cage(s), AND MUST have at least 4 RJ45 1Gb/Gigabit ports).
   - If the listing is the WRONG product type (e.g. a cable, a transceiver/SFP module alone, a media converter, a router/modem, a GPON terminal, or a 100Mbps Fast Ethernet FS-series switch when Gigabit is required), you MUST score `relevance_score` <= 10 and overall `score` <= 10!
   - If the listing is a switch but is MISSING a critical specification from the buyer's topic (e.g., it only has RJ45 with NO SFP+, or only 1G SFP instead of 10G SFP+, or only 100Mbps ports instead of 1Gb, or fewer than 4 RJ45 ports), you MUST cap `relevance_score` <= 25 and overall `score` <= 29 (Poor). Do NOT assume a product has SFP+ unless the title, description, or well-known exact model specs genuinely include SFP+!
   - Example: Netgear GS105, GS108, FS105, FS116, GS116 have NO SFP+ ports -> MUST score <= 15. A single SFP module or fiber media converter (MC200L/MC220L) is NOT a switch -> MUST score <= 10.
2. STRICT PRICE DISCIPLINE:
   - Target price is the buyer's MAXIMUM preferred budget.
   - If listing price > target price by >25%, overall score MUST be <= 45.
   - If listing price > target price, it CANNOT be MUST BUY (overall score max 85).
3. STRICT SCORE DISTRIBUTION (spread scores across 0-100):
   - 90-100 (MUST BUY - VERY RARE): Exact product type + EVERY single technical spec from the topic verified + clearly working + price well within or below target price.
   - 70-89 (EXTREMELY GOOD): Matches all key specs, working, fair price around or below target.
   - 50-69 (GOOD): Matches main product type and most specs, acceptable price or minor uncertainty/age.
   - 30-49 (BAD): Overpriced, missing a secondary requirement, poor condition, or very old hardware.
   - 0-29 (POOR): Wrong product type (max 10), defective/HS (max 15), or missing a mandatory spec from the topic (max 29).

Respond ONLY with a valid JSON object matching this exact schema:
{
  "score": 0-100,
  "price_score": 0-100,
  "working_condition_score": 0-100,
  "product_age_score": 0-100,
  "relevance_score": 0-100,
  "is_working": true|false|null,
  "estimated_age": "short string estimate or null",
  "reasoning": "concise, blunt technical appraisal explicitly stating which required specs from the buyer topic are present or missing"
}
PROMPT;

        $userPrompt = sprintf(
            "Buyer Search Topic (MANDATORY SPECS): %s\nBuyer Target Price (MAX BUDGET): %.2f EUR\nBuyer Specific Requirements: %s\n\nListing Provider: %s\nListing Title: %s\nListing Price: %.2f %s\nListing Condition State: %s\nListing Description: %s\nImages Attached: %d\n\nVerify EVERY mandatory spec from the Buyer Search Topic strictly. If the listing is not the right item or lacks required specs, score <= 10 to 29.",
            $searchRequest->getTopic(),
            $searchRequest->getTargetPrice(),
            $searchRequest->getUserRequirements() ?? 'None specified',
            $listing->getProvider(),
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
     * Enforces deterministic hard guardrails on top of LLM scores so non-matching
     * items, missing mandatory topic specs, defective units, or overpriced items
     * cannot inflate into MUST BUY / EXTREMELY GOOD brackets.
     */
    private function enforceStrictCalibration(
        SearchRequest $searchRequest,
        Listing $listing,
        ListingEvaluationResultDto $dto,
    ): ListingEvaluationResultDto {
        $topicLower = mb_strtolower($searchRequest->getTopic());
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
        $notes = [];

        // 1. Defective / broken items cannot score above 15
        if (false === $isWorking || 1 === preg_match('/\b(hs|pour pieces|pour pi[eè]ces|en panne|ne s\'allume plus|hors service|defectueux|d[eé]fectueux|cass[eé])\b/u', $combinedText)) {
            $isWorking = false;
            $workingScore = min($workingScore, 10);
            $score = min($score, 15);
            $notes[] = 'Defective/HS item capped at <=15.';
        }

        // 2. Mandatory technical token verification from buyer's topic
        $mandatoryTokens = $this->extractMandatoryTokens($topicLower);
        $missingTokens = [];
        foreach ($mandatoryTokens as $token => $pattern) {
            if (1 !== preg_match($pattern, $combinedText)) {
                $missingTokens[] = $token;
            }
        }

        // Check wrong product accessories when topic asks for a switch/device
        if (str_contains($topicLower, 'switch')) {
            $isAccessoryOnly = 1 === preg_match('/\b(c[aâ]ble|transceiver|module\s+sfp|modulo\s+sfp|media\s+converter|convertisseur|convertitore|gpon|modem|routeur|router|edgerouter|access\s+point|alimentation|alim\s+seule)\b/u', $combinedText)
                && 1 !== preg_match('/\bswitch\b/u', $combinedText);

            if ($isAccessoryOnly) {
                $relevanceScore = min($relevanceScore, 8);
                $score = min($score, 10);
                $notes[] = 'Accessory/non-switch item (capped <=10).';
            }
        }

        if ([] !== $missingTokens) {
            $missingRatio = \count($missingTokens) / max(1, \count($mandatoryTokens));
            if ($missingRatio >= 0.5) {
                $relevanceScore = min($relevanceScore, 10);
                $score = min($score, 10);
            } else {
                $relevanceScore = min($relevanceScore, 25);
                $score = min($score, 28);
            }
            $notes[] = sprintf('Missing mandatory topic spec(s): %s.', implode(', ', $missingTokens));
        }

        // 3. Price guardrail against buyer's target budget
        $targetPrice = max(1.0, $searchRequest->getTargetPrice());
        $priceRatio = $listing->getPrice() / $targetPrice;

        if ($priceRatio > 1.35) {
            $priceScore = min($priceScore, 20);
            $score = min($score, 35);
            $notes[] = sprintf('Price %.2f EUR exceeds target %.2f EUR by >35%%.', $listing->getPrice(), $targetPrice);
        } elseif ($priceRatio > 1.15) {
            $priceScore = min($priceScore, 40);
            $score = min($score, 49);
            $notes[] = sprintf('Price %.2f EUR exceeds target %.2f EUR by >15%%.', $listing->getPrice(), $targetPrice);
        } elseif ($priceRatio > 1.0) {
            $priceScore = min($priceScore, 65);
            $score = min($score, 85);
        }

        // 4. Recompute weighted composite if LLM was overly generous relative to its own sub-scores
        $weightedMax = (int) round(
            ($relevanceScore * 0.45)
            + ($priceScore * 0.25)
            + ($workingScore * 0.20)
            + ($ageScore * 0.10)
        );
        $score = min($score, $weightedMax);

        // 5. MUST BUY (>=90) is rare: requires high relevance (>=90), working, and price <= targetPrice
        if ($score >= 90 && ($relevanceScore < 90 || $priceRatio > 1.0 || false === $isWorking)) {
            $score = min($score, 88);
        }

        $reasoning = trim($dto->reasoning);
        if ([] !== $notes) {
            $reasoning .= ' [Strict Check: '.implode(' ', $notes).']';
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
            imageAnalyzed: $dto->imageAnalyzed,
        );
    }

    /**
     * Extracts mandatory specification regexes from the buyer's topic string.
     *
     * @return array<string, string>
     */
    private function extractMandatoryTokens(string $topicLower): array
    {
        $tokens = [];

        if (str_contains($topicLower, 'sfp+')) {
            // Require explicit sfp+ or 10g/10gb sfp or known 10G SFP+ switch model prefixes
            $tokens['SFP+ (10Gb)'] = '/(sfp\+|10\s*g(?:b|bps|be)?\s*sfp|sfp\s*10\s*g|crs3\d{2}|css3\d{2}|xgs\d{4}|qsw-[m\d]|tl-sg3\d{3}|xs\d{3}|usw-pro|usw-agg)/iu';
        } elseif (1 === preg_match('/\bsfp\b/u', $topicLower)) {
            $tokens['SFP'] = '/\bsfp\+?\b/iu';
        }

        if (1 === preg_match('/\b(1\s*gb|1g|gigabit|1000\s*mb|1000base)\b/u', $topicLower)) {
            $tokens['1Gb / Gigabit'] = '/(1\s*gb|1g\b|1000|gigabit|gbe|gs\d{3}|sg\d{3}|crs\d{3}|css\d{3}|t1500g|t1600g|t2500g|t2600g|xgs\d+|edgeswitch|usw-|catalyst\s+\d+g)/iu';
        }

        if (1 === preg_match('/\bswitch\b/u', $topicLower)) {
            $tokens['Switch'] = '/(switch|switche|netwerkswitch|commutateur|crs3\d{2}|css3\d{2}|edgeswitch|prosafe\s+gs|prosafe\s+xs|catalyst|powerconnect|jetstream)/iu';
        }

        if (1 === preg_match('/\brj45\b/u', $topicLower)) {
            $tokens['RJ45 / Ethernet ports'] = '/(rj45|rj-45|ethernet|ports?|gigabit|poe|base-t|cuivre|copper)/iu';
        }

        if (1 === preg_match('/\bpoe\+?\b/u', $topicLower)) {
            $tokens['PoE'] = '/\bpoe\+?\b/iu';
        }

        // Generic fallback for non-network topics: extract meaningful technical/brand tokens
        if ([] === $tokens) {
            $stopWords = [
                'with', 'without', 'least', 'most', 'more', 'less', 'than', 'from', 'that', 'this', 'have', 'for', 'and', 'the',
                'avec', 'sans', 'moins', 'plus', 'dans', 'pour', 'une', 'des', 'les', 'aux', 'sur', 'bon', 'etat', 'occasion',
            ];
            $words = preg_split('/[\s,\-\/]+/', $topicLower) ?: [];
            foreach ($words as $w) {
                $w = trim($w);
                if (mb_strlen($w) >= 3 && !in_array($w, $stopWords, true) && !ctype_digit($w)) {
                    $tokens[$w] = '/'.preg_quote($w, '/').'/iu';
                }
            }
            $tokens = \array_slice($tokens, 0, 3, true);
        }

        return $tokens;
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
            $priceScore = 62;
        } elseif ($priceRatio <= 1.35) {
            $priceScore = 42;
        } else {
            $priceScore = 20;
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
            $workingConditionScore = 15;
        } elseif (1 === preg_match('/\b(neuf|comme neuf|parfait|parfait etat|reconditionne grade a|excellent)\b/u', $combinedText)) {
            $workingConditionScore = 94;
        } elseif (1 === preg_match('/\b(tres bon|tr[eè]s bon|bon etat|bon [eé]tat|100% fonctionnel)\b/u', $combinedText)) {
            $workingConditionScore = 85;
        } elseif (1 === preg_match('/\b(etat satisfaisant|usure|rayures|fissure)\b/u', $combinedText)) {
            $workingConditionScore = 58;
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

        $topicWords = array_values(array_filter(
            preg_split('/[\s,\-\/]+/', mb_strtolower($searchRequest->getTopic())) ?: [],
            static fn (string $w): bool => mb_strlen($w) >= 2,
        ));

        $matchedWords = 0;
        foreach ($topicWords as $word) {
            if (str_contains($combinedText, $word)) {
                ++$matchedWords;
            }
        }

        $topicMatchRatio = [] !== $topicWords ? $matchedWords / \count($topicWords) : 1.0;
        $relevanceScore = (int) round(45 + ($topicMatchRatio * 50));

        if (null !== $searchRequest->getUserRequirements() && '' !== trim($searchRequest->getUserRequirements())) {
            $reqWords = array_values(array_filter(
                preg_split('/[\s,\-\/]+/', mb_strtolower($searchRequest->getUserRequirements())) ?: [],
                static fn (string $w): bool => mb_strlen($w) >= 4,
            ));
            foreach ($reqWords as $reqWord) {
                if (str_contains($combinedText, $reqWord)) {
                    $relevanceScore = min(98, $relevanceScore + 5);
                }
            }
        }

        $overallScore = (int) round(
            ($priceScore * 0.30)
            + ($workingConditionScore * 0.30)
            + ($relevanceScore * 0.25)
            + ($productAgeScore * 0.15)
        );

        if (!$isWorking) {
            $overallScore = min($overallScore, 30);
        }

        $reasoning = sprintf(
            'Prix de %.2f %s compare a la cible de %.2f EUR (score prix %d/100). Etat evalue a %d/100 (%s). Pertinence avec "%s" de %d/100.',
            $listingPrice,
            $listing->getCurrency(),
            $targetPrice,
            $priceScore,
            $workingConditionScore,
            $isWorking ? 'fonctionnel' : 'defaut/HS detecte',
            $searchRequest->getTopic(),
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

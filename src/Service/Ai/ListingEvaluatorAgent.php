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
You are an expert technical appraiser and second-hand deal evaluator.
Evaluate the provided marketplace listing against the buyer's search request across five criteria:
1. Price compared to the buyer's target price (lower than target price scores higher, suspiciously low or overpriced scores lower).
2. Working condition (detect broken items, "HS", "pour pieces", screen cracks, battery issues, or missing parts).
3. Product age and generation relevance.
4. Alignment with what the buyer truly asked for (topic and specific user requirements).
5. Your own expert technical opinion on value, reliability, and deal quality.

Respond ONLY with a valid JSON object matching this exact schema:
{
  "score": 0-100,
  "price_score": 0-100,
  "working_condition_score": 0-100,
  "product_age_score": 0-100,
  "relevance_score": 0-100,
  "is_working": true|false|null,
  "estimated_age": "short string estimate or null",
  "reasoning": "concise synthesis explaining the scores and deal quality"
}
PROMPT;

        $userPrompt = sprintf(
            "Buyer Search Topic: %s\nBuyer Target Price: %.2f EUR\nBuyer Specific Requirements: %s\n\nListing Provider: %s\nListing Title: %s\nListing Price: %.2f %s\nListing Condition State: %s\nListing Description: %s\nImages Attached: %d",
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

            return $dto->withImageAnalyzed([] !== $base64Images);
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

        return new ListingEvaluationResultDto(
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
    }
}

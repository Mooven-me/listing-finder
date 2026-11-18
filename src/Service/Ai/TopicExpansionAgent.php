<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Dto\Ai\TopicExpansionResultDto;
use Psr\Log\LoggerInterface;

final class TopicExpansionAgent
{
    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function expandTopic(
        string $topic,
        ?string $userRequirements = null,
        ?int $requestedCount = null,
    ): TopicExpansionResultDto {
        $normalizedTopic = trim($topic);
        if (null !== $userRequirements && '' !== trim($userRequirements)) {
            $normalizedTopic = trim($normalizedTopic."\n".trim($userRequirements));
        }

        if ('' === $normalizedTopic) {
            return new TopicExpansionResultDto(['produit occasion']);
        }

        $targetCount = null !== $requestedCount ? max(1, min(15, $requestedCount)) : null;
        $maxTags = $targetCount ?? 10;

        $countInstruction = null !== $targetCount
            ? sprintf('Generate EXACTLY %d distinct search queries in "sub_topics".', $targetCount)
            : 'Automatically decide the optimal number of distinct search queries (between 4 and 10) needed to thoroughly cover the buyer specification.';

        $systemPrompt = <<<PROMPT
You are a search query strategist for second-hand and refurbished marketplaces (LeBonCoin, Vinted, BackMarket).
The buyer will provide a product specification — this can be an exact product model name, a category, or a full multi-sentence description of the features, specifications, and condition they want.
Your job is to generate realistic, short marketplace search titles/scopes (2 to 4 words each) that real individual sellers and refurbishers actually write in their ad titles when selling an item that matches the buyer's specification.

Rules:
1. {$countInstruction}
2. If the buyer describes functional specifications or features without a specific model, include both:
   - Popular real brand + model names that satisfy those exact specifications.
   - Concise category + key feature search queries in French and English (since sellers on French marketplaces mix French and English terms).
3. If the buyer asks for a specific product or model (e.g. a specific printer, camera, console, tool, garment, or device), generate variations of how sellers title that exact item (brand + model, model number alone with product noun, common abbreviations, French/English product category terms).
4. Every single item in "sub_topics" MUST be a standalone, searchable product query (2 to 4 words). Never output conversational filler words ("with at least", "looking for") or bare attribute fragments without the product noun.
5. Respond ONLY with a valid JSON object: {"sub_topics": ["query 1", "query 2", ...]}
PROMPT;

        $userPrompt = sprintf(
            "Buyer Product Specification:\n%s\n\n%s\nReturn JSON {\"sub_topics\": [...]}.",
            $normalizedTopic,
            $countInstruction,
        );

        try {
            $result = $this->llmClient->generateStructured(
                $systemPrompt,
                $userPrompt,
                TopicExpansionResultDto::class,
            );

            $tags = $this->normalizeTags($normalizedTopic, $result->subTopics, false, $maxTags);

            return new TopicExpansionResultDto($tags);
        } catch (\Throwable $e) {
            $this->logger->warning('AI topic expansion failed, falling back to deterministic keyword expansion.', [
                'topic' => $normalizedTopic,
                'error' => $e->getMessage(),
            ]);

            return new TopicExpansionResultDto($this->buildDeterministicSubTopics($normalizedTopic, $maxTags));
        }
    }

    /**
     * @param array<int, mixed> $candidateTags
     *
     * @return list<string>
     */
    private function normalizeTags(
        string $mainTopic,
        array $candidateTags,
        bool $prependMainTopic = true,
        int $maxTags = 10,
    ): array {
        $seen = [];
        $normalized = [];

        $allCandidates = $prependMainTopic ? array_merge([$mainTopic], $candidateTags) : $candidateTags;
        foreach ($allCandidates as $candidate) {
            if (!\is_string($candidate)) {
                continue;
            }

            $clean = trim((string) preg_replace('/\s+/', ' ', $candidate));
            if ('' === $clean || mb_strlen($clean) < 2) {
                continue;
            }

            $key = mb_strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $clean;

            if (\count($normalized) >= $maxTags) {
                break;
            }
        }

        $fallbackTag = mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', $mainTopic)), 0, 60, '');

        return [] !== $normalized ? $normalized : [$fallbackTag];
    }

    /**
     * @return list<string>
     */
    private function buildDeterministicSubTopics(string $topic, int $maxTags = 8): array
    {
        $stopWords = [
            'with', 'without', 'least', 'most', 'more', 'less', 'than', 'from', 'that', 'this', 'have', 'having', 'for', 'and', 'the', 'looking', 'want', 'need',
            'avec', 'sans', 'moins', 'plus', 'dans', 'pour', 'une', 'des', 'les', 'aux', 'sur', 'chez', 'comme', 'tres', 'bon', 'etat', 'cherche',
        ];

        $words = array_values(array_filter(
            preg_split('/[\s,\-\/]+/', mb_strtolower($topic)) ?: [],
            static fn (string $word): bool => mb_strlen(trim($word)) >= 2
                && !in_array(trim($word), $stopWords, true),
        ));

        $coreQuery = [] !== $words ? implode(' ', \array_slice($words, 0, 4)) : trim($topic);

        $candidates = [
            $coreQuery,
        ];

        if (\count($words) >= 2) {
            $candidates[] = implode(' ', \array_slice($words, 0, 2));
            $candidates[] = implode(' ', \array_slice($words, -2));
        }

        if (\count($words) >= 3) {
            $candidates[] = implode(' ', \array_slice($words, 0, 3));
        }

        $candidates[] = sprintf('%s occasion', $coreQuery);
        $candidates[] = sprintf('%s reconditionne', $coreQuery);

        return $this->normalizeTags($coreQuery, $candidates, true, $maxTags);
    }
}

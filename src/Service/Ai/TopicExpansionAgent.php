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

    public function expandTopic(string $topic, ?string $userRequirements = null): TopicExpansionResultDto
    {
        $normalizedTopic = trim($topic);
        if ('' === $normalizedTopic) {
            return new TopicExpansionResultDto(['produit occasion']);
        }

        $systemPrompt = <<<'PROMPT'
You convert a buyer's hardware or product description into 6 to 8 short search queries for French second-hand marketplaces (LeBonCoin, Vinted) and refurbished stores (BackMarket).
Rules:
1. Half of the items in "sub_topics" MUST be short popular brand + model or brand + product queries (2 to 3 words max, e.g. "MikroTik CRS305", "MikroTik switch", "Ubiquiti EdgeSwitch", "TP-Link SFP+", "Netgear ProSafe", "Zyxel SFP+"). Avoid overly long sub-series names that sellers rarely write in full.
2. The other half MUST be short French marketplace search queries (2 to 3 words) that include the product type (e.g. "switch sfp+", "switch 10gb", "switch gigabit sfp", "switch rj45 sfp").
3. Every single item in "sub_topics" must be a standalone product search query (never output bare attribute fragments like "1 GbE" or "4 RJ45 ports" without the product noun).
4. Respond ONLY with a valid JSON object: {"sub_topics": ["query 1", "query 2", ...]}
PROMPT;

        $userPrompt = sprintf(
            "Buyer description: %s\nAdditional requirements: %s\nReturn JSON {\"sub_topics\": [...]}.",
            $normalizedTopic,
            null !== $userRequirements && '' !== trim($userRequirements) ? trim($userRequirements) : 'None specified',
        );

        try {
            $result = $this->llmClient->generateStructured(
                $systemPrompt,
                $userPrompt,
                TopicExpansionResultDto::class,
            );

            $tags = $this->normalizeTags($normalizedTopic, $result->subTopics, false);

            return new TopicExpansionResultDto($tags);
        } catch (\Throwable $e) {
            $this->logger->warning('AI topic expansion failed, falling back to deterministic keyword expansion.', [
                'topic' => $normalizedTopic,
                'error' => $e->getMessage(),
            ]);

            return new TopicExpansionResultDto($this->buildDeterministicSubTopics($normalizedTopic, $userRequirements));
        }
    }

    /**
     * @param array<int, mixed> $candidateTags
     *
     * @return list<string>
     */
    private function normalizeTags(string $mainTopic, array $candidateTags, bool $prependMainTopic = true): array
    {
        $seen = [];
        $normalized = [];

        $allCandidates = $prependMainTopic ? array_merge([$mainTopic], $candidateTags) : $candidateTags;
        foreach ($allCandidates as $candidate) {
            if (!\is_string($candidate)) {
                continue;
            }

            $clean = trim((string) preg_replace('/\s+/', ' ', $candidate));
            if ('' === $clean || mb_strlen($clean) < 3) {
                continue;
            }

            $key = mb_strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $clean;

            if (\count($normalized) >= 8) {
                break;
            }
        }

        return [] !== $normalized ? $normalized : [$mainTopic];
    }

    /**
     * @return list<string>
     */
    private function buildDeterministicSubTopics(string $topic, ?string $userRequirements): array
    {
        $stopWords = [
            'with', 'without', 'least', 'most', 'more', 'less', 'than', 'from', 'that', 'this', 'have', 'having', 'for', 'and', 'the',
            'avec', 'sans', 'moins', 'plus', 'dans', 'pour', 'une', 'des', 'les', 'aux', 'sur', 'chez', 'comme', 'tres', 'bon', 'etat',
        ];

        $words = array_values(array_filter(
            preg_split('/[\s,\-\/]+/', mb_strtolower($topic)) ?: [],
            static fn (string $word): bool => mb_strlen(trim($word)) >= 2
                && !in_array(trim($word), $stopWords, true)
                && !ctype_digit(trim($word)),
        ));

        $coreQuery = [] !== $words ? implode(' ', \array_slice($words, 0, 4)) : $topic;

        $candidates = [
            $coreQuery,
        ];

        if (\count($words) >= 2) {
            $candidates[] = implode(' ', \array_slice($words, 0, 2));
            $candidates[] = implode(' ', \array_slice($words, -2));
        }

        $candidates[] = sprintf('%s occasion', $coreQuery);
        $candidates[] = sprintf('%s reconditionne', $coreQuery);

        if (null !== $userRequirements && '' !== trim($userRequirements)) {
            $reqWords = array_values(array_filter(
                preg_split('/[\s,\-\/]+/', mb_strtolower(trim($userRequirements))) ?: [],
                static fn (string $word): bool => mb_strlen(trim($word)) >= 3 && !in_array(trim($word), $stopWords, true),
            ));

            if ([] !== $reqWords) {
                $candidates[] = sprintf('%s %s', $coreQuery, $reqWords[0]);
            }
        }

        return $this->normalizeTags($coreQuery, $candidates);
    }
}

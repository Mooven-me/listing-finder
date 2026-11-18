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
You are an e-commerce search specialist for French second-hand marketplaces (LeBonCoin, Vinted) and refurbished stores (BackMarket, Amazon).
Given a main search topic and optional buyer requirements, generate 4 to 8 highly effective search tags or keyword variations that sellers use when listing this item.
Rules:
- Respond ONLY with a valid JSON object matching this exact structure: {"sub_topics": ["tag 1", "tag 2", ...]}
- Keep tags concise (2 to 5 words), natural in French marketplace listings, and directly relevant to the requested product.
- Include common synonyms, model variations, or condition-qualified terms when relevant.
PROMPT;

        $userPrompt = sprintf(
            "Main search topic: %s\nBuyer requirements: %s\nReturn JSON with \"sub_topics\".",
            $normalizedTopic,
            null !== $userRequirements && '' !== trim($userRequirements) ? trim($userRequirements) : 'None specified',
        );

        try {
            $result = $this->llmClient->generateStructured(
                $systemPrompt,
                $userPrompt,
                TopicExpansionResultDto::class,
            );

            $tags = $this->normalizeTags($normalizedTopic, $result->subTopics);

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
    private function normalizeTags(string $mainTopic, array $candidateTags): array
    {
        $seen = [];
        $normalized = [];

        $allCandidates = array_merge([$mainTopic], $candidateTags);
        foreach ($allCandidates as $candidate) {
            if (!\is_string($candidate)) {
                continue;
            }

            $clean = trim((string) preg_replace('/\s+/', ' ', $candidate));
            if ('' === $clean) {
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
        $candidates = [
            $topic,
            sprintf('%s occasion', $topic),
            sprintf('%s reconditionne', $topic),
            sprintf('%s bon etat', $topic),
        ];

        $words = array_values(array_filter(
            preg_split('/[\s,\-\/]+/', $topic) ?: [],
            static fn (string $word): bool => mb_strlen(trim($word)) >= 3,
        ));

        if (\count($words) >= 2) {
            $candidates[] = implode(' ', \array_slice($words, 0, 2));
        }

        if (null !== $userRequirements && '' !== trim($userRequirements)) {
            $reqWords = array_values(array_filter(
                preg_split('/[\s,\-\/]+/', trim($userRequirements)) ?: [],
                static fn (string $word): bool => mb_strlen(trim($word)) >= 4,
            ));

            if ([] !== $reqWords) {
                $candidates[] = sprintf('%s %s', $topic, $reqWords[0]);
            }
        }

        return $this->normalizeTags($topic, $candidates);
    }
}

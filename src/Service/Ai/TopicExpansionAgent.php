<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Dto\Ai\TopicExpansionResultDto;
use Psr\Log\LoggerInterface;

final class TopicExpansionAgent
{
    private const MAX_QUERY_WORDS = 4;

    /** @var list<string> */
    private const STOP_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'of', 'in', 'on', 'to', 'at', 'by', 'as', 'is', 'are', 'be',
        'with', 'without', 'least', 'most', 'more', 'less', 'than', 'from', 'that', 'this', 'have', 'having', 'for',
        'looking', 'want', 'need', 'needs', 'very', 'good', 'great', 'best', 'nice',
        'avec', 'sans', 'moins', 'plus', 'dans', 'pour', 'une', 'des', 'les', 'aux', 'sur', 'chez', 'comme',
        'tres', 'très', 'bon', 'bonne', 'etat', 'état', 'cherche', 'recherche', 'de', 'du', 'la', 'le', 'un', 'et', 'ou',
    ];

    /** @var list<string> */
    private const FORBIDDEN_META_WORDS = [
        'leboncoin', 'vinted', 'backmarket', 'ebay', 'amazon',
        'specification', 'specifications', 'spec', 'specs',
        'review', 'reviews', 'seller', 'sellers', 'buyer',
        'condition', 'included', 'second-hand', 'secondhand',
        'looking', 'wanted', 'buy', 'buying', 'model', 'models',
    ];

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
        $maxTags = $targetCount ?? 8;

        $countInstruction = null !== $targetCount
            ? sprintf('Generate EXACTLY %d distinct short search queries in "sub_topics".', $targetCount)
            : 'Automatically decide the optimal number of distinct short search queries (between 5 and 8) needed to cover the buyer specification.';

        $systemPrompt = <<<PROMPT
You generate short 2-to-3-word marketplace search queries for second-hand classified ads (France and international).
Classified ad search bars use strict AND matching, so long queries return zero results.
{$countInstruction}
Every query MUST contain the main product noun (fixing any spelling typo) plus only 1 or 2 keywords (a key feature, synonym, French/English equivalent, or exact model code from the buyer).
Never exceed 3 words per query. Never alter alphanumeric model codes from the buyer, never invent unrelated brands or models, and never include meta words (model, specification, specs, condition, reviews, seller, or website names).
Respond ONLY with valid JSON: {"sub_topics": ["query 1", "query 2", ...]}
PROMPT;

        $userPrompt = sprintf(
            "Buyer Product Specification:\n%s\n\n%s Keep every query strictly 2 to 3 words.",
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
            $tags = $this->ensureHighRecallAnchors($normalizedTopic, $tags, $maxTags);

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
        int $maxTags = 8,
    ): array {
        $seen = [];
        $normalized = [];

        $allCandidates = $prependMainTopic ? array_merge([$mainTopic], $candidateTags) : $candidateTags;
        foreach ($allCandidates as $candidate) {
            if (!\is_string($candidate)) {
                continue;
            }

            $clean = $this->sanitizeQuery($candidate);
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

        if ([] !== $normalized) {
            return $normalized;
        }

        return $this->buildDeterministicSubTopics($mainTopic, max(1, min(4, $maxTags)));
    }

    /**
     * Strips forbidden meta words, edge stopwords, and compacts overly long queries (> 4 words)
     * so strict-AND marketplace search engines actually return results.
     */
    private function sanitizeQuery(string $raw): string
    {
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $raw));
        if ('' === $collapsed) {
            return '';
        }

        $rawTokens = preg_split('/\s+/', $collapsed) ?: [];
        $tokens = [];

        foreach ($rawTokens as $token) {
            // Preserve internal/trailing symbols like '+' (e.g. SFP+), '%' (e.g. 75%), '-' (e.g. TP-Link)
            $cleanToken = trim($token, ".,;:!?()[]{}\"'`~*/\\");
            if ('' === $cleanToken) {
                continue;
            }

            $lower = mb_strtolower($cleanToken);
            if (\in_array($lower, self::FORBIDDEN_META_WORDS, true)) {
                continue;
            }

            $tokens[] = $cleanToken;
        }

        // Trim leading and trailing stopwords (e.g. "Canon AX380 in" -> "Canon AX380")
        while ([] !== $tokens && \in_array(mb_strtolower($tokens[0]), self::STOP_WORDS, true)) {
            array_shift($tokens);
        }
        while ([] !== $tokens && \in_array(mb_strtolower($tokens[\count($tokens) - 1]), self::STOP_WORDS, true)) {
            array_pop($tokens);
        }

        if (\count($tokens) > self::MAX_QUERY_WORDS) {
            // Drop interior stopwords first before truncating
            $meaningful = array_values(array_filter(
                $tokens,
                static fn (string $t): bool => !\in_array(mb_strtolower($t), self::STOP_WORDS, true),
            ));
            $tokens = [] !== $meaningful ? $meaningful : $tokens;
        }

        if (\count($tokens) > self::MAX_QUERY_WORDS) {
            $tokens = \array_slice($tokens, 0, self::MAX_QUERY_WORDS);
        }

        return implode(' ', $tokens);
    }

    /**
     * Domain-agnostic high-recall guardrail:
     * 1. Preserves exact alphanumeric model codes written by the buyer (prevents LLM code mutation).
     * 2. Ensures at least 2 concise 2-word queries exist (head noun + distinctive token) so strict-AND
     *    marketplaces never return 0 results solely because all AI queries had 3-4 words.
     *
     * @param list<string> $aiTags
     *
     * @return list<string>
     */
    private function ensureHighRecallAnchors(string $rawTopic, array $aiTags, int $maxTags): array
    {
        $anchors = [];

        // 1. Preserve exact alphanumeric codes from the buyer's input (e.g. "ax380", "40L", "rtx4070")
        $topicTokens = $this->extractMeaningfulTokens($rawTopic);
        $alphanumericCodes = array_values(array_filter(
            $topicTokens,
            static fn (string $t): bool => 1 === preg_match('/^(?=.*[a-zA-Z])(?=.*\d)[a-zA-Z0-9+._-]{2,20}$/', $t),
        ));

        // Detect most frequent meaningful token across AI queries (typically the corrected core product noun)
        $headNoun = $this->detectHeadNoun($aiTags, $topicTokens);

        // If buyer wrote an exact alphanumeric code (like a product model) and no AI tag preserved it, anchor it
        foreach (\array_slice($alphanumericCodes, 0, 2) as $code) {
            $codeLower = mb_strtolower($code);
            $alreadyPresent = false;
            foreach ($aiTags as $tag) {
                if (str_contains(mb_strtolower($tag), $codeLower)) {
                    $alreadyPresent = true;
                    break;
                }
            }

            if (!$alreadyPresent) {
                $prefix = null;
                foreach ($topicTokens as $t) {
                    if (mb_strtolower($t) !== $codeLower && mb_strlen($t) >= 3 && !is_numeric($t)) {
                        $prefix = $t;
                        break;
                    }
                }
                if (null !== $headNoun && mb_strtolower($headNoun) !== $codeLower) {
                    $anchors[] = sprintf('%s %s', $headNoun, $code);
                } elseif (null !== $prefix) {
                    $anchors[] = sprintf('%s %s', $prefix, $code);
                }
            }
        }

        // 2. Ensure at least 2 ultra-broad 2-word queries exist when we have a clear head noun
        $shortCount = 0;
        foreach ($aiTags as $tag) {
            $wordCount = \count(preg_split('/\s+/', trim($tag)) ?: []);
            if ($wordCount <= 2) {
                ++$shortCount;
            }
        }

        if ($shortCount < 2 && null !== $headNoun) {
            $headLower = mb_strtolower($headNoun);
            $distinctiveTokens = $this->extractDistinctiveModifiers($rawTopic, $aiTags, $headLower);
            foreach (\array_slice($distinctiveTokens, 0, 3) as $mod) {
                $anchors[] = sprintf('%s %s', $headNoun, $mod);
            }
        }

        if ([] === $anchors) {
            return \array_slice($aiTags, 0, $maxTags);
        }

        // Put high-recall 2-word anchors first so they are never dropped by $maxTags
        $merged = [];
        $seen = [];
        foreach (array_merge($anchors, $aiTags) as $candidate) {
            $clean = $this->sanitizeQuery($candidate);
            if ('' === $clean) {
                continue;
            }
            $key = mb_strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $clean;
            if (\count($merged) >= $maxTags) {
                break;
            }
        }

        return $merged;
    }

    /**
     * @param list<string> $aiTags
     * @param list<string> $topicTokens
     */
    private function detectHeadNoun(array $aiTags, array $topicTokens): ?string
    {
        $counts = [];
        $positionSum = [];
        $originalCase = [];

        foreach ($aiTags as $tag) {
            $words = preg_split('/\s+/', trim($tag)) ?: [];
            $wordCount = max(1, \count($words));
            $seenInTag = [];
            foreach ($words as $idx => $word) {
                $lower = mb_strtolower($word);
                if (mb_strlen($lower) < 3 || 1 === preg_match('/\d/', $lower) || isset($seenInTag[$lower]) || \in_array($lower, self::STOP_WORDS, true) || \in_array($lower, self::FORBIDDEN_META_WORDS, true)) {
                    continue;
                }
                $seenInTag[$lower] = true;
                $counts[$lower] = ($counts[$lower] ?? 0) + 1;
                $positionSum[$lower] = ($positionSum[$lower] ?? 0.0) + (($idx + 1) / $wordCount);
                $originalCase[$lower] ??= $word;
            }
        }

        if ([] !== $counts) {
            $keys = array_keys($counts);
            usort($keys, static function (string $a, string $b) use ($counts, $positionSum): int {
                $freqCmp = $counts[$b] <=> $counts[$a];
                if (0 !== $freqCmp) {
                    return $freqCmp;
                }

                // Tie-break toward the rightmost word in noun phrases (e.g. "internet switch" -> "switch")
                return ($positionSum[$b] / $counts[$b]) <=> ($positionSum[$a] / $counts[$a]);
            });

            $topWord = $keys[0];
            if (($counts[$topWord] ?? 0) >= 2) {
                return $originalCase[$topWord] ?? $topWord;
            }
        }

        foreach ($topicTokens as $token) {
            if (mb_strlen($token) >= 3 && !is_numeric($token)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * @param list<string> $aiTags
     *
     * @return list<string>
     */
    private function extractDistinctiveModifiers(string $rawTopic, array $aiTags, string $headLower): array
    {
        $buyerCandidates = [];
        $aiCandidates = [];
        $seen = [$headLower => true];

        // 1. High-frequency companion words from AI queries (e.g. "Ethernet", "Gigabit", "Fanless")
        $aiWordCounts = [];
        $aiWordOriginal = [];
        foreach ($aiTags as $tag) {
            foreach (preg_split('/\s+/', trim($tag)) ?: [] as $word) {
                $lower = mb_strtolower($word);
                if (isset($seen[$lower]) || mb_strlen($lower) < 3 || is_numeric($lower) || \in_array($lower, self::STOP_WORDS, true) || \in_array($lower, self::FORBIDDEN_META_WORDS, true)) {
                    continue;
                }
                $aiWordCounts[$lower] = ($aiWordCounts[$lower] ?? 0) + 1;
                $aiWordOriginal[$lower] ??= $word;
            }
        }
        arsort($aiWordCounts);
        foreach (array_keys($aiWordCounts) as $lower) {
            $aiCandidates[] = $aiWordOriginal[$lower];
        }

        // 2. Technical/distinctive tokens from the buyer input (prioritizing tokens with '+', '%', or digits)
        $topicTokens = $this->extractMeaningfulTokens($rawTopic);
        usort($topicTokens, static function (string $a, string $b): int {
            $scoreA = (1 === preg_match('/[+\d%]/', $a) ? 2 : 0) + (mb_strlen($a) >= 4 ? 1 : 0);
            $scoreB = (1 === preg_match('/[+\d%]/', $b) ? 2 : 0) + (mb_strlen($b) >= 4 ? 1 : 0);

            return $scoreB <=> $scoreA;
        });

        foreach ($topicTokens as $token) {
            $lower = mb_strtolower($token);
            if (isset($seen[$lower]) || mb_strlen($lower) < 3 || is_numeric($lower)) {
                continue;
            }
            if (\count($aiTags) >= 2 && 0 === preg_match('/[+\d%]/', $token)) {
                $foundInAi = false;
                foreach ($aiTags as $tag) {
                    if (str_contains(mb_strtolower($tag), $lower)) {
                        $foundInAi = true;
                        break;
                    }
                }
                if (!$foundInAi) {
                    continue;
                }
            }

            $buyerCandidates[] = $token;
        }

        // Interleave top AI companion words and buyer distinctive tokens for balanced broad + specific recall
        $merged = [];
        $maxLen = max(\count($aiCandidates), \count($buyerCandidates));
        for ($i = 0; $i < $maxLen; ++$i) {
            if (isset($buyerCandidates[$i])) {
                $k = mb_strtolower($buyerCandidates[$i]);
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $merged[] = $buyerCandidates[$i];
                }
            }
            if (isset($aiCandidates[$i])) {
                $k = mb_strtolower($aiCandidates[$i]);
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $merged[] = $aiCandidates[$i];
                }
            }
        }

        return $merged;
    }

    /**
     * @return list<string>
     */
    private function extractMeaningfulTokens(string $text): array
    {
        $rawTokens = preg_split('/[\s,\/;()]+/', trim($text)) ?: [];
        $tokens = [];

        foreach ($rawTokens as $token) {
            $clean = trim($token, ".,:!?[]{}\"'`~*");
            $lower = mb_strtolower($clean);
            if (mb_strlen($clean) < 2 || \in_array($lower, self::STOP_WORDS, true) || \in_array($lower, self::FORBIDDEN_META_WORDS, true)) {
                continue;
            }
            $tokens[] = $clean;
        }

        return $tokens;
    }

    /**
     * @return list<string>
     */
    private function buildDeterministicSubTopics(string $topic, int $maxTags = 8): array
    {
        $words = $this->extractMeaningfulTokens($topic);
        $coreQuery = [] !== $words ? implode(' ', \array_slice($words, 0, 3)) : trim($topic);

        $candidates = [
            $coreQuery,
        ];

        if (\count($words) >= 2) {
            $candidates[] = implode(' ', \array_slice($words, 0, 2));
            $candidates[] = implode(' ', \array_slice($words, -2));
        }

        if (\count($words) >= 3) {
            $candidates[] = sprintf('%s %s', $words[0], $words[2]);
            $candidates[] = implode(' ', \array_slice($words, 0, 3));
        }

        $candidates[] = sprintf('%s occasion', implode(' ', \array_slice($words, 0, 2)) ?: $coreQuery);

        return $this->normalizeTags($coreQuery, $candidates, true, $maxTags);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\Ai\TopicExpansionResultDto;
use App\Service\Ai\LlmClientInterface;
use App\Service\Ai\TopicExpansionAgent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TopicExpansionAgentTest extends TestCase
{
    public function testCompactsVerboseLlmQueriesStripsMetaWordsAndAnchorsShortQueries(): void
    {
        $llm = new class implements LlmClientInterface {
            public function generateStructured(
                string $systemPrompt,
                string $userPrompt,
                string $dtoClass,
                array $base64Images = [],
            ): object {
                return new TopicExpansionResultDto([
                    'Industrial Ethernet Switch 4 Port RJ45 1Gbps Fanless Specification',
                    'Business Ethernet Switch 4 RJ45 1Gbps Fanless Model',
                    'LeBonCoin Dual SFP+ Ethernet Switch Reviews',
                    'Vinted Fanless Switch Seller Condition',
                ]);
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function getVisionModelName(): string
            {
                return 'test-model';
            }
        };

        $agent = new TopicExpansionAgent($llm, new NullLogger());
        $result = $agent->expandTopic('an internet swicht with two sfp+ port and more than four rj45 1Gbps port that is fanless');

        self::assertNotEmpty($result->subTopics);

        $shortQueriesCount = 0;
        foreach ($result->subTopics as $query) {
            $words = preg_split('/\s+/', trim($query)) ?: [];
            self::assertLessThanOrEqual(4, \count($words), sprintf('Query "%s" exceeded 4 words.', $query));

            $lower = mb_strtolower($query);
            self::assertStringNotContainsString('specification', $lower);
            self::assertStringNotContainsString('leboncoin', $lower);
            self::assertStringNotContainsString('vinted', $lower);
            self::assertStringNotContainsString('reviews', $lower);
            self::assertStringNotContainsString('seller', $lower);

            if (\count($words) <= 2) {
                ++$shortQueriesCount;
            }
        }

        // At least 2 high-recall 2-word anchor queries must be present
        self::assertGreaterThanOrEqual(2, $shortQueriesCount);
    }

    public function testPreservesExactAlphanumericCodeWhenLlmMutatesIt(): void
    {
        $llm = new class implements LlmClientInterface {
            public function generateStructured(
                string $systemPrompt,
                string $userPrompt,
                string $dtoClass,
                array $base64Images = [],
            ): object {
                return new TopicExpansionResultDto([
                    'Canon A3600 printer',
                    'A3600 printer',
                    'Canon printer in',
                ]);
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function getVisionModelName(): string
            {
                return 'test-model';
            }
        };

        $agent = new TopicExpansionAgent($llm, new NullLogger());
        $result = $agent->expandTopic('canon printer ax380 in working condition with power cable', null, 5);

        self::assertLessThanOrEqual(5, \count($result->subTopics));

        $hasExactCode = false;
        foreach ($result->subTopics as $query) {
            if (str_contains(mb_strtolower($query), 'ax380')) {
                $hasExactCode = true;
            }
            self::assertDoesNotMatchRegularExpression('/\bin$/i', $query);
        }

        self::assertTrue($hasExactCode, 'Expected exact buyer model code "ax380" to be preserved in sub_topics.');
    }

    public function testCoreProductNounAnchorsBareAttributeQueriesToPreventAccessoryFlood(): void
    {
        $llm = new class implements LlmClientInterface {
            public function generateStructured(
                string $systemPrompt,
                string $userPrompt,
                string $dtoClass,
                array $base64Images = [],
            ): object {
                return new TopicExpansionResultDto(
                    subTopics: ['SFP+ RJ45', 'SFP+ 10Gbps', '4x RJ45 10Gbps', 'commutateur SFP+'],
                    coreProductNoun: 'switch',
                    coreProductSynonyms: ['commutateur'],
                );
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function getVisionModelName(): string
            {
                return 'llama-3.1-8b-instant';
            }
        };

        $agent = new TopicExpansionAgent($llm, new NullLogger());
        $result = $agent->expandTopic(
            'i would like a switch with at least 4 RJ45 1Gbps ports and 2 SFP+ ports, otherwise 4 RJ45 10Gbps ports without SFP+',
        );

        self::assertNotEmpty($result->subTopics);
        foreach ($result->subTopics as $query) {
            $lower = mb_strtolower($query);
            self::assertTrue(
                str_contains($lower, 'switch') || str_contains($lower, 'commutateur'),
                sprintf('Expected query "%s" to contain core product noun "switch" or synonym "commutateur".', $query),
            );
        }
    }
}

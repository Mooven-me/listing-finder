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
}

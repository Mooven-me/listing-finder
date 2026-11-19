<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\Ai\ListingEvaluationResultDto;
use App\Entity\Listing;
use App\Entity\SearchRequest;
use App\Service\Ai\LlmClientInterface;
use App\Service\Ai\ListingEvaluatorAgent;
use App\Service\Ai\SearxngProductSpecService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ListingEvaluatorAgentTest extends TestCase
{
    public function testMissingSpecsCapScoreEvenWhenLlmReturnsHighScore(): void
    {
        $capturedPrompts = [];
        $llm = new class($capturedPrompts) implements LlmClientInterface {
            /** @param array<int, string> $captured */
            public function __construct(public array &$captured)
            {
            }

            public function generateStructured(
                string $systemPrompt,
                string $userPrompt,
                string $dtoClass,
                array $base64Images = [],
            ): object {
                $this->captured[] = $userPrompt;

                return new ListingEvaluationResultDto(
                    score: 92,
                    priceScore: 95,
                    workingConditionScore: 95,
                    productAgeScore: 85,
                    relevanceScore: 95,
                    isWorking: true,
                    estimatedAge: 'unknown',
                    reasoning: 'Looks cheap.',
                    matchedSpecs: ['5 ports 1Gbps'],
                    missingOrUnverifiedSpecs: ['two SFP+ ports', 'fanless design'],
                    isWrongProductOrAccessory: false,
                    imageAnalyzed: false,
                );
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function getVisionModelName(): string
            {
                return 'lfm2.5:8b';
            }
        };

        $imageDownloads = 0;
        $httpClient = new MockHttpClient(function () use (&$imageDownloads): MockResponse {
            ++$imageDownloads;

            return new MockResponse('img-bytes');
        });

        $search = new SearchRequest(
            topic: 'an internet switch with two sfp+ ports and more than four rj45 1Gbps ports fanless',
            targetPrice: 50.0,
            enableImages: true,
        );
        $listing = new Listing(
            searchRequest: $search,
            provider: 'leboncoin',
            externalId: '3186705199',
            searchTopicUsed: 'switch 1Gbps',
            title: 'Switch ethernet 1Gbps 1000mbps 5 ports',
            price: 10.0,
            url: 'https://www.leboncoin.fr/ad/ordinateurs/3186705199',
            description: '',
            imageUrls: ['https://img.example.test/1.jpg'],
            conditionState: 'Très bon état',
        );

        $agent = new ListingEvaluatorAgent($llm, $httpClient, new NullLogger());
        $result = $agent->evaluate($search, $listing);

        // 2+ missing/unverified specs must cap score & relevance at <= 29 (POOR)
        self::assertLessThanOrEqual(29, $result->score);
        self::assertLessThanOrEqual(29, $result->relevanceScore);
        self::assertFalse($result->imageAnalyzed);
        // Must NOT download images when vision is unsupported
        self::assertSame(0, $imageDownloads);
        // Empty description must be normalized rather than blank
        self::assertStringContainsString('No seller description provided.', $capturedPrompts[0]);
        self::assertStringContainsString('Missing/unverified: two SFP+ ports, fanless design', $result->reasoning);
    }

    public function testWrongProductOrAccessoryIsCappedAtTenAndUsesSearxngContext(): void
    {
        $capturedPrompts = [];
        $llm = new class($capturedPrompts) implements LlmClientInterface {
            /** @param array<int, string> $captured */
            public function __construct(public array &$captured)
            {
            }

            public function generateStructured(
                string $systemPrompt,
                string $userPrompt,
                string $dtoClass,
                array $base64Images = [],
            ): object {
                $this->captured[] = $userPrompt;

                return new ListingEvaluationResultDto(
                    score: 43,
                    priceScore: 85,
                    workingConditionScore: 80,
                    productAgeScore: 50,
                    relevanceScore: 38,
                    isWorking: true,
                    estimatedAge: 'unknown',
                    reasoning: 'This listing is an Ethernet cable, not a switch.',
                    matchedSpecs: [],
                    missingOrUnverifiedSpecs: ['switch'],
                    isWrongProductOrAccessory: true,
                    imageAnalyzed: false,
                );
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function getVisionModelName(): string
            {
                return 'lfm2.5:8b';
            }
        };

        $searxHttp = new MockHttpClient([
            new MockResponse((string) json_encode([
                'results' => [
                    [
                        'title' => 'NETGEAR FS605v3 Datasheet',
                        'content' => '5-Port 10/100 Fast Ethernet Unmanaged Switch, no Gigabit, no SFP+.',
                    ],
                ],
            ])),
        ]);
        $specService = new SearxngProductSpecService($searxHttp, new ArrayAdapter(), new NullLogger(), 'http://searxng:8080', 5);

        $search = new SearchRequest(
            topic: 'an internet switch with two sfp+ ports and more than four rj45 1Gbps ports',
            targetPrice: 50.0,
        );
        $listing = new Listing(
            searchRequest: $search,
            provider: 'leboncoin',
            externalId: '3223568185',
            searchTopicUsed: 'switch 1Gbps',
            title: 'Switch Netgear FS605v3',
            price: 10.0,
            url: 'https://www.leboncoin.fr/ad/ordinateurs/3223568185',
            description: 'Switch Netgear FS605v3 5 ports',
        );

        $agent = new ListingEvaluatorAgent($llm, new MockHttpClient(), new NullLogger(), $specService);
        $result = $agent->evaluate($search, $listing);

        self::assertLessThanOrEqual(10, $result->score);
        self::assertLessThanOrEqual(10, $result->relevanceScore);
        self::assertStringContainsString('NETGEAR FS605v3 Datasheet', $capturedPrompts[0]);
        self::assertStringContainsString('5-Port 10/100 Fast Ethernet', $capturedPrompts[0]);
    }

    public function testUnverifiedExactTechnicalTokenCapsHallucinatedLlmScore(): void
    {
        $llm = new class implements LlmClientInterface {
            public function generateStructured(
                string $systemPrompt,
                string $userPrompt,
                string $dtoClass,
                array $base64Images = [],
            ): object {
                // Simulates an 8B model hallucinating a perfect score despite missing "sfp+"
                return new ListingEvaluationResultDto(
                    score: 94,
                    priceScore: 100,
                    workingConditionScore: 100,
                    productAgeScore: 90,
                    relevanceScore: 100,
                    isWorking: true,
                    estimatedAge: 'recent',
                    reasoning: 'Matches all specs.',
                    matchedSpecs: ['sfp+'],
                    missingOrUnverifiedSpecs: [],
                    isWrongProductOrAccessory: false,
                    imageAnalyzed: false,
                );
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function getVisionModelName(): string
            {
                return 'lfm2.5:8b';
            }
        };

        $search = new SearchRequest(
            topic: 'an internet switch with two sfp+ port and more than four rj45 1Gbps port',
            targetPrice: 50.0,
        );

        // Listing only has SFP (1G), NOT SFP+ (10G)
        $sfpOnlyListing = new Listing(
            searchRequest: $search,
            provider: 'leboncoin',
            externalId: '3166185125',
            searchTopicUsed: 'switch sfp+',
            title: 'Switch Linksys LGS326',
            price: 30.0,
            url: 'https://www.leboncoin.fr/ad/ordinateurs/3166185125',
            description: '24 ports 1Gbps + 2 ports SFP',
            conditionState: 'Très bon état',
        );

        // Listing genuinely has SFP+
        $sfpPlusListing = new Listing(
            searchRequest: $search,
            provider: 'leboncoin',
            externalId: '9999991',
            searchTopicUsed: 'switch sfp+',
            title: 'Switch MikroTik CSS326-24G-2S+RM',
            price: 45.0,
            url: 'https://www.leboncoin.fr/ad/ordinateurs/9999991',
            description: '24 ports Gigabit RJ45 1Gbps + 2 ports SFP+ 10Gbps',
            conditionState: 'Très bon état',
        );

        $agent = new ListingEvaluatorAgent($llm, new MockHttpClient(), new NullLogger());

        $sfpOnlyResult = $agent->evaluate($search, $sfpOnlyListing);
        self::assertLessThanOrEqual(29, $sfpOnlyResult->score);
        self::assertStringContainsString('sfp+', mb_strtolower($sfpOnlyResult->reasoning));

        $sfpPlusResult = $agent->evaluate($search, $sfpPlusListing);
        self::assertGreaterThanOrEqual(90, $sfpPlusResult->score);
    }
}

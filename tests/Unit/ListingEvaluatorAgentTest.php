<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\Ai\ListingEvaluationResultDto;
use App\Dto\Ai\ProductTypeGatekeeperResultDto;
use App\Entity\Listing;
use App\Entity\SearchRequest;
use App\Service\Ai\ListingEvaluatorAgent;
use App\Service\Ai\LlmClientInterface;
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
                if (ProductTypeGatekeeperResultDto::class === $dtoClass) {
                    return new ProductTypeGatekeeperResultDto('network switch', 'network switch', true, false, 'Both are switches.');
                }

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
                return 'llama-3.1-8b-instant';
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

        self::assertLessThanOrEqual(29, $result->score);
        self::assertLessThanOrEqual(29, $result->relevanceScore);
        self::assertFalse($result->imageAnalyzed);
        self::assertSame(0, $imageDownloads);
        self::assertStringContainsString('No seller description provided.', $capturedPrompts[0]);
        self::assertStringContainsString('Missing/unverified: two SFP+ ports, fanless design', $result->reasoning);
    }

    public function testStageOneGatekeeperRejectsModuleAndCableWithoutCallingSearxng(): void
    {
        $llmCalls = 0;
        $llm = new class($llmCalls) implements LlmClientInterface {
            public function __construct(public int &$calls)
            {
            }

            public function generateStructured(
                string $systemPrompt,
                string $userPrompt,
                string $dtoClass,
                array $base64Images = [],
            ): object {
                ++$this->calls;

                return new ProductTypeGatekeeperResultDto('network switch', 'transceiver', false, true, 'Accessory only.');
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

        $searxCalls = 0;
        $searxHttp = new MockHttpClient(function () use (&$searxCalls): MockResponse {
            ++$searxCalls;

            return new MockResponse('{"results":[]}');
        });
        $specService = new SearxngProductSpecService($searxHttp, new ArrayAdapter(), new NullLogger(), 'http://searxng:8080', 5);

        $search = new SearchRequest(
            topic: 'a network switch with at least 4 RJ45 1Gbps ports and 2 SFP+ ports',
            targetPrice: 50.0,
        );
        $moduleListing = new Listing(
            searchRequest: $search,
            provider: 'leboncoin',
            externalId: '3203277948',
            searchTopicUsed: 'switch sfp+',
            title: 'Module SFP+ 10Gb RJ45 - freebox ultra',
            price: 23.0,
            url: 'https://www.leboncoin.fr/ad/accessoires_informatique/3203277948',
        );
        $cableListing = new Listing(
            searchRequest: $search,
            provider: 'leboncoin',
            externalId: '3250992624',
            searchTopicUsed: 'switch 1Gbps',
            title: 'YGREEN Câble Ethernet 15m Cat 6 Plat Gigabit 1Gbps RJ45',
            price: 14.0,
            url: 'https://www.leboncoin.fr/ad/accessoires_informatique/3250992624',
        );

        $agent = new ListingEvaluatorAgent($llm, new MockHttpClient(), new NullLogger(), $specService);

        $moduleRes = $agent->evaluate($search, $moduleListing);
        $cableRes = $agent->evaluate($search, $cableListing);

        self::assertLessThanOrEqual(10, $moduleRes->score);
        self::assertTrue($moduleRes->isWrongProductOrAccessory);
        self::assertLessThanOrEqual(10, $cableRes->score);
        self::assertTrue($cableRes->isWrongProductOrAccessory);
        // Zero SearXNG calls and zero LLM calls needed for explicit title accessories!
        self::assertSame(0, $searxCalls);
        self::assertSame(0, $llmCalls);
    }

    public function testStageTwoInjectsSearxngContextAndSupportsConditionalSpecifications(): void
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
                if (ProductTypeGatekeeperResultDto::class === $dtoClass) {
                    return new ProductTypeGatekeeperResultDto('network switch', 'network switch', true, false, 'Both are switches.');
                }

                $this->captured[] = $userPrompt;

                return new ListingEvaluationResultDto(
                    score: 93,
                    priceScore: 95,
                    workingConditionScore: 95,
                    productAgeScore: 90,
                    relevanceScore: 98,
                    isWorking: true,
                    estimatedAge: 'recent',
                    reasoning: 'Matches the 10Gbps RJ45 branch (no SFP+ needed).',
                    matchedSpecs: ['5x 10Gbps RJ45 ports'],
                    missingOrUnverifiedSpecs: [],
                    isWrongProductOrAccessory: false,
                    buyerRequestedProductType: 'network switch',
                    listingActualProductType: '10G network switch',
                    imageAnalyzed: false,
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

        $searxHttp = new MockHttpClient([
            new MockResponse((string) json_encode([
                'results' => [
                    [
                        'title' => 'NETGEAR XS505M Datasheet',
                        'content' => '5-Port 10G Multi-Gigabit Ethernet Unmanaged Switch.',
                    ],
                ],
            ])),
        ]);
        $specService = new SearxngProductSpecService($searxHttp, new ArrayAdapter(), new NullLogger(), 'http://searxng:8080', 5);

        $search = new SearchRequest(
            topic: 'a switch with 4 RJ45 1Gbps + 2 SFP+, otherwise 4 RJ45 10Gbps without SFP+ (not mandatory)',
            targetPrice: 120.0,
        );
        $tenGigSwitch = new Listing(
            searchRequest: $search,
            provider: 'leboncoin',
            externalId: '8888881',
            searchTopicUsed: 'switch 10Gbps',
            title: 'Switch Netgear XS505M 5 ports 10Gbps RJ45',
            price: 95.0,
            url: 'https://www.leboncoin.fr/ad/ordinateurs/8888881',
            description: 'Switch 5 ports RJ45 10Gbps parfait état.',
        );

        $agent = new ListingEvaluatorAgent($llm, new MockHttpClient(), new NullLogger(), $specService);
        $result = $agent->evaluate($search, $tenGigSwitch);

        // Conditional spec must NOT falsely penalize missing "sfp+" when the 10Gbps RJ45 branch is satisfied
        self::assertGreaterThanOrEqual(90, $result->score);
        self::assertStringContainsString('NETGEAR XS505M Datasheet', $capturedPrompts[0]);
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
                if (ProductTypeGatekeeperResultDto::class === $dtoClass) {
                    return new ProductTypeGatekeeperResultDto('network switch', 'network switch', true, false, 'Both are switches.');
                }

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
                return 'llama-3.1-8b-instant';
            }
        };

        $search = new SearchRequest(
            topic: 'an internet switch with two sfp+ port and more than four rj45 1Gbps port',
            targetPrice: 50.0,
        );

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

    public function testStageOneGatekeeperDoesNotRejectOnNegatedAccessoryReason(): void
    {
        $llm = new class implements LlmClientInterface {
            public function generateStructured(
                string $systemPrompt,
                string $userPrompt,
                string $dtoClass,
                array $base64Images = [],
            ): object {
                if (ProductTypeGatekeeperResultDto::class === $dtoClass) {
                    return new ProductTypeGatekeeperResultDto(
                        buyerWantsItemType: 'network switch',
                        listingSellsItemType: 'network switch',
                        isSameCoreProduct: true,
                        isAccessoryOrComponentOnly: false,
                        briefReason: 'Both buyer and listing are for a network switch; the listing is not merely an accessory or component.',
                    );
                }

                return new ListingEvaluationResultDto(
                    score: 88,
                    priceScore: 95,
                    workingConditionScore: 95,
                    productAgeScore: 85,
                    relevanceScore: 92,
                    isWorking: true,
                    estimatedAge: 'recent',
                    reasoning: 'Valid switch matching specs.',
                    matchedSpecs: ['24x 1Gbps RJ45', '2x SFP+'],
                    missingOrUnverifiedSpecs: [],
                    isWrongProductOrAccessory: false,
                    buyerRequestedProductType: 'network switch',
                    listingActualProductType: 'network switch',
                    imageAnalyzed: false,
                );
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function getVisionModelName(): string
            {
                return 'openai/gpt-oss-120b';
            }
        };

        $search = new SearchRequest(
            topic: 'a network switch with two sfp+ ports and 4 rj45 1Gbps ports',
            targetPrice: 50.0,
        );
        $listing = new Listing(
            searchRequest: $search,
            provider: 'leboncoin',
            externalId: '9999992',
            searchTopicUsed: 'switch sfp+',
            title: 'Switch MikroTik CSS326',
            price: 45.0,
            url: 'https://www.leboncoin.fr/ad/ordinateurs/9999992',
            description: '24 ports Gigabit 1Gbps + 2 ports SFP+',
            conditionState: 'Très bon état',
        );

        $agent = new ListingEvaluatorAgent($llm, new MockHttpClient(), new NullLogger());
        self::assertNull($agent->checkCoreProductGatekeeper($search, $listing));

        $result = $agent->evaluate($search, $listing);
        self::assertGreaterThanOrEqual(85, $result->score);
        self::assertFalse($result->isWrongProductOrAccessory);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dto\Ai\ListingEvaluationResultDto;
use App\Dto\Ai\TopicExpansionResultDto;
use App\Entity\ListingEvaluation;
use App\Enum\DealCategory;
use App\Message\EvaluateListingMessage;
use App\Message\FetchSubTopicListingsMessage;
use App\MessageHandler\EvaluateListingHandler;
use App\MessageHandler\FetchSubTopicListingsHandler;
use App\Repository\ListingEvaluationRepository;
use App\Repository\ListingRepository;
use App\Repository\SearchRequestRepository;
use App\Service\Ai\ConfigurableLlmClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SearchPipelineTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM listing_evaluation');
        $connection->executeStatement('DELETE FROM listing');
        $connection->executeStatement('DELETE FROM search_request');
    }

    public function testConfigurableLlmClientDeserializesAndValidatesOllamaJson(): void
    {
        $container = self::getContainer();
        $serializer = $container->get(SerializerInterface::class);
        $validator = $container->get(ValidatorInterface::class);

        $mockHttp = new MockHttpClient([
            new MockResponse((string) json_encode([
                'message' => [
                    'content' => "```json\n{\"sub_topics\": [\"switch sfp+\", \"sfp+ 10gb\", \"mikrotik crs305\"]}\n```",
                ],
            ])),
            new MockResponse((string) json_encode([
                'message' => [
                    'content' => '{"score": 94, "price_score": 96, "working_condition_score": 95, "product_age_score": 90, "relevance_score": 95, "is_working": true, "estimated_age": "1 year", "reasoning": "Outstanding SFP+ switch deal well below target price."}',
                ],
            ])),
        ]);

        $client = new ConfigurableLlmClient(
            httpClient: $mockHttp,
            serializer: $serializer,
            validator: $validator,
            provider: 'ollama',
            baseUrl: 'http://localhost:11434',
            apiKey: '',
            model: 'lfm2.5:8b',
            visionModel: 'lfm2.5:8b',
            timeout: 10,
        );

        $expansion = $client->generateStructured('system', 'user', TopicExpansionResultDto::class);
        self::assertSame(['switch sfp+', 'sfp+ 10gb', 'mikrotik crs305'], $expansion->subTopics);

        $evaluation = $client->generateStructured('system', 'user', ListingEvaluationResultDto::class, [base64_encode('fake-img')]);
        self::assertSame(94, $evaluation->score);
        self::assertTrue($evaluation->isWorking);
        self::assertSame(DealCategory::MustBuy, DealCategory::fromScore($evaluation->score));
    }

    public function testEndToEndSearchStartWorkersAndResultsCommands(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $startCommand = $application->find('app:search:start');
        $startTester = new CommandTester($startCommand);
        $exitCode = $startTester->execute([
            'topic' => 'sfp+ switch',
            '--price' => '120',
            '--with-images' => true,
            '--providers' => 'mock,leboncoin',
            '--limit' => '5',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Search task #', $startTester->getDisplay());

        $container = self::getContainer();
        $searchRepo = $container->get(SearchRequestRepository::class);
        $listingRepo = $container->get(ListingRepository::class);
        $evalRepo = $container->get(ListingEvaluationRepository::class);
        $fetchHandler = $container->get(FetchSubTopicListingsHandler::class);
        $evalHandler = $container->get(EvaluateListingHandler::class);

        $searchRequest = $searchRepo->findByIdentifierOrLatest('sfp+ switch');
        self::assertNotNull($searchRequest);
        self::assertNotEmpty($searchRequest->getGeneratedSubTopics());
        self::assertTrue($searchRequest->isEnableImages());

        $firstSubTopic = $searchRequest->getGeneratedSubTopics()[0];
        ($fetchHandler)(new FetchSubTopicListingsMessage(
            searchRequestId: (int) $searchRequest->getId(),
            subTopic: $firstSubTopic,
            providerName: 'mock',
            limit: 5,
        ));

        $listings = $listingRepo->findAll();
        self::assertCount(5, $listings);

        foreach ($listings as $listing) {
            ($evalHandler)(new EvaluateListingMessage(
                searchRequestId: (int) $searchRequest->getId(),
                listingId: (int) $listing->getId(),
            ));
        }

        $evaluations = $evalRepo->findForSearchRequest($searchRequest);
        self::assertCount(5, $evaluations);

        $categories = array_map(static fn (ListingEvaluation $e): DealCategory => $e->getCategory(), $evaluations);
        self::assertContains(DealCategory::MustBuy, $categories);
        self::assertContains(DealCategory::Good, $categories);
        self::assertContains(DealCategory::Pass, $categories);

        $resultsCommand = $application->find('app:search:results');
        $resultsTester = new CommandTester($resultsCommand);
        $resultsExitCode = $resultsTester->execute([
            'search' => 'sfp+ switch',
        ]);

        self::assertSame(0, $resultsExitCode);
        $output = $resultsTester->getDisplay();
        self::assertStringContainsString('MUST BUY (Score 90-100 / 100 | 9.0-10.0 / 10)', $output);
        self::assertStringContainsString('GOOD DEALS (Score 70-89 / 100 | 7.0-8.9 / 10)', $output);
    }
}

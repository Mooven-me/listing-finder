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
use App\Repository\CronTaskRepository;
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
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
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
        $connection->executeStatement('DELETE FROM cron_task');
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
                    'content' => "```json\n{\"sub_topics\": [\"canon printer ax380\", \"imprimante canon ax380\", \"canon pixma ax380\"]}\n```",
                ],
            ])),
            new MockResponse((string) json_encode([
                'message' => [
                    'content' => '{"score": 94, "price_score": 96, "working_condition_score": 95, "product_age_score": 90, "relevance_score": 95, "is_working": true, "estimated_age": "1 year", "reasoning": "Outstanding Canon printer deal well below target price."}',
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
        self::assertSame(['canon printer ax380', 'imprimante canon ax380', 'canon pixma ax380'], $expansion->subTopics);

        $evaluation = $client->generateStructured('system', 'user', ListingEvaluationResultDto::class, [base64_encode('fake-img')]);
        self::assertSame(94, $evaluation->score);
        self::assertTrue($evaluation->isWorking);
        self::assertSame(DealCategory::MustBuy, DealCategory::fromScore($evaluation->score));
    }

    public function testConfigurableLlmClientFailsFastOnOpenAi429WithLongRetryAfter(): void
    {
        $container = self::getContainer();
        $serializer = $container->get(SerializerInterface::class);
        $validator = $container->get(ValidatorInterface::class);

        $calls = 0;
        $mockHttp = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"error":{"message":"Rate limit reached"}}', [
                'http_code' => 429,
                'response_headers' => ['Retry-After: 86'],
            ]);
        });

        $client = new ConfigurableLlmClient(
            httpClient: $mockHttp,
            serializer: $serializer,
            validator: $validator,
            provider: 'openai',
            baseUrl: 'https://api.groq.com/openai/v1',
            apiKey: 'test-key',
            model: 'openai/gpt-oss-120b',
            visionModel: 'openai/gpt-oss-120b',
            timeout: 10,
        );

        $started = microtime(true);
        try {
            $client->generateStructured('system', 'user', TopicExpansionResultDto::class);
            self::fail('Expected RuntimeException on HTTP 429.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('HTTP 429', $e->getMessage());
        }

        $elapsed = microtime(true) - $started;
        self::assertSame(1, $calls, 'Should fail immediately on Retry-After > maxRetrySleepSec without retrying.');
        self::assertLessThan(1.0, $elapsed, 'Fast fail should not sleep when Retry-After exceeds retry window.');
    }

    public function testEndToEndSearchStartIncrementalCronAndStopCommands(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $startCommand = $application->find('app:search:start');
        $startTester = new CommandTester($startCommand);
        $exitCode = $startTester->execute([
            'topic' => 'canon printer ax380',
            '--price' => '120',
            '--with-images' => true,
            '--providers' => 'mock',
            '--limit' => '4',
            '--interval' => '30',
        ]);

        self::assertSame(0, $exitCode);
        $normalizedStartDisplay = (string) preg_replace('/\s+/', ' ', $startTester->getDisplay());
        self::assertStringContainsString('registered in cron_task table', $normalizedStartDisplay);

        $container = self::getContainer();
        $searchRepo = $container->get(SearchRequestRepository::class);
        $cronRepo = $container->get(CronTaskRepository::class);
        $listingRepo = $container->get(ListingRepository::class);
        $evalRepo = $container->get(ListingEvaluationRepository::class);
        $fetchHandler = $container->get(FetchSubTopicListingsHandler::class);
        $evalHandler = $container->get(EvaluateListingHandler::class);
        /** @var InMemoryTransport $asyncTransport */
        $asyncTransport = $container->get('messenger.transport.async');

        $searchRequest = $searchRepo->findByIdentifierOrLatest('canon printer ax380');
        self::assertNotNull($searchRequest);
        self::assertNotNull($searchRequest->getCronTask());
        self::assertSame(30, $searchRequest->getCronTask()->getIntervalMinutes());

        $firstSubTopic = $searchRequest->getGeneratedSubTopics()[0];
        $asyncTransport->reset();

        // Initial run fetches 4 listings for the first sub-topic
        ($fetchHandler)(new FetchSubTopicListingsMessage(
            searchRequestId: (int) $searchRequest->getId(),
            subTopic: $firstSubTopic,
            providerName: 'mock',
            limit: 4,
        ));

        $listings = $listingRepo->findBy(['searchRequest' => $searchRequest]);
        self::assertCount(4, $listings);
        self::assertCount(4, $asyncTransport->getSent());

        foreach ($listings as $listing) {
            ($evalHandler)(new EvaluateListingMessage(
                searchRequestId: (int) $searchRequest->getId(),
                listingId: (int) $listing->getId(),
            ));
        }

        $evaluations = $evalRepo->findForSearchRequest($searchRequest);
        self::assertCount(4, $evaluations);

        foreach ($listingRepo->findBy(['searchRequest' => $searchRequest]) as $storedListing) {
            self::assertTrue($storedListing->isComputed());
        }

        // Second run (simulating 30-minute cron where 1 new ad appears: limit 5 instead of 4)
        $asyncTransport->reset();
        ($fetchHandler)(new FetchSubTopicListingsMessage(
            searchRequestId: (int) $searchRequest->getId(),
            subTopic: $firstSubTopic,
            providerName: 'mock',
            limit: 5,
        ));

        // Only the 1 new ad (not yet computed) should be dispatched for evaluation!
        self::assertCount(1, $asyncTransport->getSent());
        self::assertCount(5, $listingRepo->findBy(['searchRequest' => $searchRequest]));

        // Execute the 30-minute cron runner command (--force)
        $cronRunCommand = $application->find('app:cron:run');
        $cronRunTester = new CommandTester($cronRunCommand);
        self::assertSame(0, $cronRunTester->execute(['--force' => true]));
        self::assertStringContainsString('1 cron task(s) executed', $cronRunTester->getDisplay());

        // Stop/remove the cron task for this topic
        $stopCommand = $application->find('app:search:stop');
        $stopTester = new CommandTester($stopCommand);
        self::assertSame(0, $stopTester->execute(['search' => 'canon printer ax380']));
        self::assertStringContainsString('Disabled and removed CronTask', $stopTester->getDisplay());
        self::assertCount(0, $cronRepo->findAll());

        // Subsequent cron run executes 0 tasks because the CronTask entity was removed
        self::assertSame(0, $cronRunTester->execute(['--force' => true]));
        self::assertStringContainsString('0 cron task(s) executed', $cronRunTester->getDisplay());

        // Results remain available via app:search:results
        $resultsCommand = $application->find('app:search:results');
        $resultsTester = new CommandTester($resultsCommand);
        self::assertSame(0, $resultsTester->execute(['search' => 'canon printer ax380']));
        $output = $resultsTester->getDisplay();
        self::assertStringContainsString('MUST BUY (Score 90-100)', $output);
        self::assertStringContainsString('EXTREMELY GOOD (Score 70-89)', $output);
        self::assertStringContainsString('GOOD (Score 50-69)', $output);
        self::assertStringContainsString('DISABLED (Removed from cron_task table)', $output);
        self::assertStringContainsString('[AI ANALYSIS]', $output);

        // Results sorted by score (--by-score)
        self::assertSame(0, $resultsTester->execute(['search' => 'canon printer ax380', '--by-score' => true]));
        $scoreOutput = $resultsTester->getDisplay();
        self::assertStringContainsString('RECOMMENDED DEALS (SCORE >= 50/100) — SORTED BY SCORE', $scoreOutput);
        self::assertStringContainsString('BY SCORE (High -> Low)', $scoreOutput);

        // Results filtered by a single category argument (must_buy)
        self::assertSame(0, $resultsTester->execute(['search' => 'canon printer ax380', 'category' => 'must_buy']));
        $onlyMustBuyOutput = $resultsTester->getDisplay();
        self::assertStringContainsString('MUST BUY (Score 90-100)', $onlyMustBuyOutput);
        self::assertStringNotContainsString('EXTREMELY GOOD (Score 70-89)', $onlyMustBuyOutput);
    }
}

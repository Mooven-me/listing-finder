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

    public function testEndToEndSearchStartIncrementalCronAndStopCommands(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $startCommand = $application->find('app:search:start');
        $startTester = new CommandTester($startCommand);
        $exitCode = $startTester->execute([
            'topic' => 'sfp+ switch',
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

        $searchRequest = $searchRepo->findByIdentifierOrLatest('sfp+ switch');
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
        self::assertSame(0, $stopTester->execute(['search' => 'sfp+ switch']));
        self::assertStringContainsString('Disabled and removed CronTask', $stopTester->getDisplay());
        self::assertCount(0, $cronRepo->findAll());

        // Subsequent cron run executes 0 tasks because the CronTask entity was removed
        self::assertSame(0, $cronRunTester->execute(['--force' => true]));
        self::assertStringContainsString('0 cron task(s) executed', $cronRunTester->getDisplay());

        // Results remain available via app:search:results
        $resultsCommand = $application->find('app:search:results');
        $resultsTester = new CommandTester($resultsCommand);
        self::assertSame(0, $resultsTester->execute(['search' => 'sfp+ switch']));
        $output = $resultsTester->getDisplay();
        self::assertStringContainsString('MUST BUY (Score 90-100 / 100 | 9.0-10.0 / 10)', $output);
        self::assertStringContainsString('GOOD DEALS (Score 70-89 / 100 | 7.0-8.9 / 10)', $output);
        self::assertStringContainsString('DISABLED (Removed from cron_task table)', $output);
        self::assertStringContainsString('[AI ANALYSIS]', $output);

        // Results sorted by score (--by-score)
        self::assertSame(0, $resultsTester->execute(['search' => 'sfp+ switch', '--by-score' => true]));
        $scoreOutput = $resultsTester->getDisplay();
        self::assertStringContainsString('TOP DEALS (SCORE >= 70/100) — SORTED BY SCORE', $scoreOutput);
        self::assertStringContainsString('BY SCORE (High -> Low)', $scoreOutput);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CronTask;
use App\Entity\Listing;
use App\Entity\ListingEvaluation;
use App\Entity\SearchRequest;
use App\Enum\SearchStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testDashboardRendersKpisListingsAndSupportsFilters(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM listing_evaluation');
        $connection->executeStatement('DELETE FROM listing');
        $connection->executeStatement('DELETE FROM cron_task');
        $connection->executeStatement('DELETE FROM search_request');

        $searchRequest = new SearchRequest(
            topic: 'sfp+ switch test',
            targetPrice: 50.0,
            enableImages: false,
            userRequirements: 'Fanless preferred',
            enabledProviders: ['leboncoin', 'vinted'],
        );
        $searchRequest->setGeneratedSubTopics(['MikroTik CRS305', 'switch sfp+']);
        $searchRequest->setStatus(SearchStatus::Completed);

        $cronTask = new CronTask($searchRequest, 30, 25);
        $searchRequest->setCronTask($cronTask);

        $listingMustBuy = new Listing(
            searchRequest: $searchRequest,
            provider: 'leboncoin',
            externalId: 'lbc-101',
            searchTopicUsed: 'MikroTik CRS305',
            title: 'MikroTik CRS305-1G-4S+IN Parfait Etat',
            price: 35.0,
            url: 'https://www.leboncoin.fr/ad/accessoires_informatique/101',
            description: 'Switch 10Gb SFP+ complet en boite',
            currency: 'EUR',
            imageUrls: ['https://placehold.co/300x300'],
            conditionState: 'Très bon état',
        );
        $listingMustBuy->setIsComputed(true);

        $evalMustBuy = new ListingEvaluation(
            searchRequest: $searchRequest,
            listing: $listingMustBuy,
            scoreOutOf100: 95,
            priceScore: 98,
            workingConditionScore: 95,
            productAgeScore: 90,
            relevanceScore: 96,
            aiReasoning: 'Excellent MikroTik SFP+ deal 30% below target price.',
            imageAnalyzed: false,
            isWorking: true,
            estimatedAge: '1-2 years',
        );

        $listingGood = new Listing(
            searchRequest: $searchRequest,
            provider: 'vinted',
            externalId: 'vnt-202',
            searchTopicUsed: 'switch sfp+',
            title: 'Netgear ProSafe GS108T Gigabit Switch',
            price: 45.0,
            url: 'https://www.vinted.fr/items/202',
            description: 'Bon etat general',
            currency: 'EUR',
            imageUrls: [],
            conditionState: 'Bon état',
        );
        $listingGood->setIsComputed(true);

        $evalGood = new ListingEvaluation(
            searchRequest: $searchRequest,
            listing: $listingGood,
            scoreOutOf100: 82,
            priceScore: 85,
            workingConditionScore: 85,
            productAgeScore: 75,
            relevanceScore: 80,
            aiReasoning: 'Solid gigabit switch under target budget.',
            imageAnalyzed: false,
            isWorking: true,
            estimatedAge: '2-3 years',
        );

        $em->persist($searchRequest);
        $em->persist($cronTask);
        $em->persist($listingMustBuy);
        $em->persist($evalMustBuy);
        $em->persist($listingGood);
        $em->persist($evalGood);
        $em->flush();

        $searchId = (int) $searchRequest->getId();

        // 1. Default dashboard view (By Category)
        $client->request('GET', '/?search='.$searchId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'sfp+ switch test');
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('MikroTik CRS305-1G-4S+IN Parfait Etat', $content);
        self::assertStringContainsString('Netgear ProSafe GS108T Gigabit Switch', $content);
        self::assertStringContainsString('Excellent MikroTik SFP+ deal 30% below target price.', $content);

        // 2. Filter by single category (must_buy)
        $client->request('GET', sprintf('/?search=%d&category=must_buy', $searchId));
        self::assertResponseIsSuccessful();
        $mustBuyContent = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('MikroTik CRS305-1G-4S+IN Parfait Etat', $mustBuyContent);
        self::assertStringNotContainsString('Netgear ProSafe GS108T Gigabit Switch', $mustBuyContent);

        // 3. Sort by score + compact table view + pagination
        $client->request('GET', sprintf('/?search=%d&sort=score&view=table&perPage=10&page=1', $searchId));
        self::assertResponseIsSuccessful();
        $tableContent = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('1-2', $tableContent);

        // 4. JSON Status API endpoint
        $client->request('GET', sprintf('/api/dashboard/status/%d', $searchId));
        self::assertResponseIsSuccessful();
        $statusPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($statusPayload);
        self::assertSame(2, $statusPayload['total_ads']);
        self::assertSame(2, $statusPayload['evaluated_ads']);
        self::assertSame(1, $statusPayload['must_buy_count']);
        self::assertSame(1, $statusPayload['extremely_good_count']);
        self::assertSame(100, $statusPayload['progress_percent']);
    }
}

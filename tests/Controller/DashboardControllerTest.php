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
            topic: 'canon printer ax380 test',
            targetPrice: 50.0,
            enableImages: false,
            userRequirements: 'Power cable included',
            enabledProviders: ['leboncoin', 'vinted'],
            providerOptions: [
                'leboncoin' => [
                    'sort' => 'relevance',
                    'allow_shipping' => true,
                    'allow_near_me' => true,
                    'location' => 'Lyon',
                    'radius_km' => 30,
                ],
            ],
        );
        $searchRequest->setGeneratedSubTopics(['Canon AX380', 'imprimante canon ax380']);
        $searchRequest->setStatus(SearchStatus::Completed);

        $cronTask = new CronTask($searchRequest, 30, null);
        $searchRequest->setCronTask($cronTask);

        $listingMustBuy = new Listing(
            searchRequest: $searchRequest,
            provider: 'leboncoin',
            externalId: 'lbc-101',
            searchTopicUsed: 'Canon AX380',
            title: 'Canon Printer AX380 Parfait Etat',
            price: 35.0,
            url: 'https://www.leboncoin.fr/ad/informatique/101',
            description: 'Imprimante Wi-Fi complet en boite',
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
            aiReasoning: 'Excellent Canon AX380 printer deal 30% below target price.',
            imageAnalyzed: false,
            isWorking: true,
            estimatedAge: '1-2 years',
        );

        $listingGood = new Listing(
            searchRequest: $searchRequest,
            provider: 'vinted',
            externalId: 'vnt-202',
            searchTopicUsed: 'imprimante canon ax380',
            title: 'Canon Pixma AX380 Wi-Fi Printer',
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
            aiReasoning: 'Solid printer under target budget.',
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
        self::assertSelectorTextContains('h2', 'canon printer ax380 test');
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Canon Printer AX380 Parfait Etat', $content);
        self::assertStringContainsString('Canon Pixma AX380 Wi-Fi Printer', $content);
        self::assertStringContainsString('Excellent Canon AX380 printer deal 30% below target price.', $content);
        self::assertStringContainsString('Unlimited (All pages)', $content);
        self::assertStringContainsString('near:Lyon (30km)', $content);
        self::assertStringContainsString('leboncoin Options', $content);
        self::assertStringContainsString('vinted Options', $content);
        self::assertStringContainsString('Max Ads / Query', $content);
        // Vision checkbox is greyed out / disabled when model does not support vision
        self::assertStringContainsString('does not support vision', $content);

        // 2. Filter by single category (must_buy)
        $mustBuyCrawler = $client->request('GET', sprintf('/?search=%d&category=must_buy', $searchId));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $mustBuyCrawler->filter('article'));
        $mustBuyCardText = $mustBuyCrawler->filter('article')->text();
        self::assertStringContainsString('Canon Printer AX380 Parfait Etat', $mustBuyCardText);
        self::assertStringNotContainsString('Canon Pixma AX380 Wi-Fi Printer', $mustBuyCardText);

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
        self::assertArrayHasKey('discovery_timeline', $statusPayload);
        self::assertArrayHasKey('unseen_total', $statusPayload);
        self::assertArrayHasKey('sold_count', $statusPayload);
        self::assertArrayHasKey('provider_health', $statusPayload);

        // 4b. Price Asc / Price Desc / Newest sorting + onlyNew + sold hiding
        $client->request('GET', sprintf('/?search=%d&sort=price_asc', $searchId));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Price Asc', (string) $client->getResponse()->getContent());

        $client->request('GET', sprintf('/?search=%d&sort=newest&onlyNew=1', $searchId));
        self::assertResponseIsSuccessful();

        // Mark one listing sold and confirm it is hidden by default unless showSold=1
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'UPDATE listing SET is_sold = true WHERE external_id = :ext',
            ['ext' => 'vnt-202'],
        );
        $client->request('GET', sprintf('/?search=%d', $searchId));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Canon Pixma AX380 Wi-Fi Printer', (string) $client->getResponse()->getContent());
        $client->request('GET', sprintf('/?search=%d&showSold=1', $searchId));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Canon Pixma AX380 Wi-Fi Printer', (string) $client->getResponse()->getContent());

        // 5. Processing search with 0 stored ads and empty queue transitions cleanly to Completed
        $emptySearch = new SearchRequest(
            topic: 'rare empty query test',
            targetPrice: 40.0,
            enableImages: false,
            enabledProviders: ['leboncoin'],
        );
        $emptySearch->setGeneratedSubTopics(['rare empty']);
        $emptySearch->setStatus(SearchStatus::Processing);
        $em->persist($emptySearch);
        $em->flush();

        $client->request('GET', sprintf('/api/dashboard/status/%d', (int) $emptySearch->getId()));
        self::assertResponseIsSuccessful();
        $emptyStatus = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($emptyStatus);
        self::assertSame('completed', $emptyStatus['status']);
        self::assertSame(0, $emptyStatus['total_ads']);
    }
}

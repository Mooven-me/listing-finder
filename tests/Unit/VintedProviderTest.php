<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Provider\MockListingProvider;
use App\Service\Provider\VintedProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class VintedProviderTest extends TestCase
{
    public function testRelevanceSortConditionFilterAndMinPriceQueryParams(): void
    {
        $capturedUrls = [];

        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrls): MockResponse {
            $capturedUrls[] = $url;

            return new MockResponse($this->buildCatalogHtml(1, 3));
        });

        $provider = new VintedProvider(
            httpClient: $httpClient,
            logger: new NullLogger(),
            mockListingProvider: new MockListingProvider(),
            decoder: new JsonEncoder(),
            fallbackToMock: false,
            maxUnlimitedPages: 5,
        );

        $results = $provider->search('canon printer ax380', 60.0, 10, [
            'sort' => 'relevance',
            'min_condition' => 'very_good_plus',
            'min_price' => 10,
        ]);

        self::assertCount(1, $capturedUrls);
        parse_str((string) parse_url($capturedUrls[0], \PHP_URL_QUERY), $query);

        self::assertSame('canon printer ax380', $query['search_text'] ?? null);
        self::assertSame('relevance', $query['order'] ?? null);
        self::assertSame('10', $query['price_from'] ?? null);
        self::assertSame('90', $query['price_to'] ?? null);
        self::assertSame(['6', '1', '2'], $query['status_ids'] ?? null);

        self::assertCount(3, $results);
        self::assertSame('1001', $results[0]->externalId);
        self::assertSame('Très bon état', $results[0]->conditionState);
    }

    public function testUnlimitedMultiPageCrawlingStopsAfterPartialLastPage(): void
    {
        $requestedPages = [];

        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedPages): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $page = isset($query['page']) ? (int) $query['page'] : 1;
            $requestedPages[] = $page;

            if (1 === $page) {
                return new MockResponse($this->buildCatalogHtml(1, 22));
            }

            return new MockResponse($this->buildCatalogHtml(23, 4));
        });

        $provider = new VintedProvider(
            httpClient: $httpClient,
            logger: new NullLogger(),
            mockListingProvider: new MockListingProvider(),
            decoder: new JsonEncoder(),
            fallbackToMock: false,
            maxUnlimitedPages: 5,
        );

        $results = $provider->search('canon printer ax380', 80.0, null, []);

        self::assertSame([1, 2], $requestedPages);
        self::assertCount(26, $results);
        self::assertSame('1001', $results[0]->externalId);
        self::assertSame('1026', $results[25]->externalId);
    }

    public function testValidSsrZeroResultsDoesNotCallApiFallback(): void
    {
        $capturedUrls = [];

        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrls): MockResponse {
            $capturedUrls[] = sprintf('%s %s', $method, $url);

            return new MockResponse('<!doctype html><html><body><div class="catalog-empty">Aucun resultat</div></body></html>');
        });

        $provider = new VintedProvider(
            httpClient: $httpClient,
            logger: new NullLogger(),
            mockListingProvider: new MockListingProvider(),
            decoder: new JsonEncoder(),
            fallbackToMock: false,
            maxUnlimitedPages: 5,
        );

        $results = $provider->search('very obscure nonexistent item', 50.0, 20, []);

        self::assertSame([], $results);
        self::assertCount(1, $capturedUrls);
        self::assertStringContainsString('www.vinted.fr/catalog', $capturedUrls[0]);
    }

    public function testFetchListingDetailsDetectsSoldVintedItemFromEscapedRscFlags(): void
    {
        $soldHtml = '<!doctype html><html><body><script>self.__next_f.push([1,"{\"is_closed\":true,\"can_buy\":false,\"description\":\"Switch 8 ports\"}"])</script></body></html>';
        $provider = new VintedProvider(
            httpClient: new MockHttpClient([new MockResponse($soldHtml)]),
            logger: new NullLogger(),
            mockListingProvider: new MockListingProvider(),
            decoder: new JsonEncoder(),
            fallbackToMock: false,
            maxUnlimitedPages: 5,
        );

        $details = $provider->fetchListingDetails('https://www.vinted.fr/items/10752865759-switch');
        self::assertNotNull($details);
        self::assertTrue($details->isSoldOrUnavailable);
    }

    private function buildCatalogHtml(int $startIndex, int $count): string
    {
        $itemsHtml = '';
        for ($i = $startIndex; $i < $startIndex + $count; ++$i) {
            $id = 1000 + $i;
            $itemsHtml .= sprintf(
                '<div class="feed-grid__item"><img src="https://img.vinted.test/%d.jpg" /><a href="/items/%d-canon-printer" title="Canon Printer AX380 #%d, Marque: Canon, État: Très bon état, 35.00 €, 37.50 €"></a></div>',
                $id,
                $id,
                $i,
            );
        }

        return sprintf('<!doctype html><html><body>%s</body></html>', $itemsHtml);
    }
}

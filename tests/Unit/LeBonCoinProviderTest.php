<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Provider\FrenchGeocoderService;
use App\Service\Provider\LeBonCoinProvider;
use App\Service\Provider\ListingProviderRegistry;
use App\Service\Provider\MockListingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class LeBonCoinProviderTest extends TestCase
{
    public function testOptionDefinitionsAndNormalizationHandleUncheckedCheckboxes(): void
    {
        $provider = $this->createProvider(new MockHttpClient());
        $definitions = $provider->getOptionDefinitions();
        $names = array_map(static fn ($d): string => $d->name, $definitions);

        self::assertContains('sort', $names);
        self::assertContains('allow_shipping', $names);
        self::assertContains('allow_near_me', $names);
        self::assertContains('location', $names);
        self::assertContains('radius_km', $names);
        self::assertContains('search_in_title', $names);
        self::assertContains('owner_type', $names);
        self::assertContains('min_price', $names);

        $registry = new ListingProviderRegistry([$provider], 'leboncoin');

        // Form submitted with all checkboxes unchecked and out-of-range radius
        $normalized = $registry->normalizeProviderOptions([
            'leboncoin' => [
                '_submitted' => '1',
                'sort' => 'newest',
                'radius_km' => '999',
                'owner_type' => 'private',
                'min_price' => '15',
            ],
        ], ['leboncoin']);

        self::assertSame('newest', $normalized['leboncoin']['sort']);
        self::assertFalse($normalized['leboncoin']['allow_shipping']);
        self::assertFalse($normalized['leboncoin']['allow_near_me']);
        self::assertFalse($normalized['leboncoin']['search_in_title']);
        self::assertSame(200.0, $normalized['leboncoin']['radius_km']);
        self::assertSame('private', $normalized['leboncoin']['owner_type']);
        self::assertSame(15.0, $normalized['leboncoin']['min_price']);

        // Default normalization (no submission key) keeps allow_shipping=true and sort=relevance
        $defaults = $registry->normalizeProviderOptions([], ['leboncoin']);
        self::assertSame('relevance', $defaults['leboncoin']['sort']);
        self::assertTrue($defaults['leboncoin']['allow_shipping']);
        self::assertFalse($defaults['leboncoin']['allow_near_me']);
        self::assertSame(30.0, $defaults['leboncoin']['radius_km']);
    }

    public function testDefaultSearchUsesRelevanceAndNationwideShipping(): void
    {
        $capturedRequests = [];

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedRequests): MockResponse {
            $capturedRequests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse($this->buildNextDataHtml([
                [
                    'list_id' => 1001,
                    'subject' => 'Canon Printer AX380 Bon Etat',
                    'body' => 'Fonctionne parfaitement avec cable secteur',
                    'price' => [40],
                    'url' => 'https://www.leboncoin.fr/ad/1001',
                    'images' => ['urls' => ['https://img.example.test/1001.jpg']],
                    'attributes' => [['key' => 'condition', 'value_label' => 'Très bon état']],
                    'first_publication_date' => '2026-11-18 12:00:00',
                ],
            ], maxPages: 1));
        });

        $provider = $this->createProvider($httpClient);
        $results = $provider->search('canon printer ax380', 50.0, 20, []);

        self::assertCount(1, $capturedRequests);
        $url = $capturedRequests[0]['url'];
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        self::assertSame('canon printer ax380', $query['text'] ?? null);
        self::assertArrayNotHasKey('sort', $query);
        self::assertSame('mondial_relay,colissimo,chronopost,shop2shop,relais_colis', $query['shipping_type'] ?? null);
        self::assertSame('min-75', $query['price'] ?? null);

        // Ensure no Referer header is sent to LeBonCoin SSR (avoids DataDome 403)
        $headers = $capturedRequests[0]['options']['normalized_headers'] ?? [];
        self::assertArrayNotHasKey('referer', $headers);

        self::assertCount(1, $results);
        self::assertSame('1001', $results[0]->externalId);
        self::assertSame('Canon Printer AX380 Bon Etat', $results[0]->title);
        self::assertSame(40.0, $results[0]->price);
        self::assertSame('Très bon état', $results[0]->conditionState);
    }

    public function testUnionShippingAndNearMeGeocodesCityAndDeduplicatesResults(): void
    {
        $capturedUrls = [];

        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrls): MockResponse {
            $capturedUrls[] = $url;

            if (str_contains($url, 'api-adresse.data.gouv.fr')) {
                return new MockResponse((string) json_encode([
                    'features' => [[
                        'geometry' => ['coordinates' => [4.8357, 45.764]],
                        'properties' => ['city' => 'Lyon', 'postcode' => '69001'],
                    ]],
                ]));
            }

            if (str_contains($url, 'shipping_type=')) {
                return new MockResponse($this->buildNextDataHtml([
                    ['list_id' => 101, 'subject' => 'Ad Shipping Only', 'price' => [35], 'url' => 'https://www.leboncoin.fr/ad/101'],
                    ['list_id' => 102, 'subject' => 'Ad Both Shipping And Local', 'price' => [40], 'url' => 'https://www.leboncoin.fr/ad/102'],
                ], maxPages: 1));
            }

            return new MockResponse($this->buildNextDataHtml([
                ['list_id' => 102, 'subject' => 'Ad Both Shipping And Local', 'price' => [40], 'url' => 'https://www.leboncoin.fr/ad/102'],
                ['list_id' => 103, 'subject' => 'Ad Local Pickup Only', 'price' => [30], 'url' => 'https://www.leboncoin.fr/ad/103'],
            ], maxPages: 1));
        });

        $provider = $this->createProvider($httpClient);
        $results = $provider->search('canon printer ax380', 50.0, null, [
            'allow_shipping' => true,
            'allow_near_me' => true,
            'location' => 'Lyon',
            'radius_km' => 25,
            'search_in_title' => true,
            'owner_type' => 'private',
            'min_price' => 15,
        ]);

        // 1 geocoder call + 2 LeBonCoin passes (shipping + local radius)
        self::assertCount(3, $capturedUrls);
        self::assertStringContainsString('api-adresse.data.gouv.fr', $capturedUrls[0]);

        parse_str((string) parse_url($capturedUrls[1], \PHP_URL_QUERY), $pass1Query);
        self::assertSame('mondial_relay,colissimo,chronopost,shop2shop,relais_colis', $pass1Query['shipping_type'] ?? null);
        self::assertSame('subject', $pass1Query['search_in'] ?? null);
        self::assertSame('private', $pass1Query['owner_type'] ?? null);
        self::assertSame('15-75', $pass1Query['price'] ?? null);

        parse_str((string) parse_url($capturedUrls[2], \PHP_URL_QUERY), $pass2Query);
        self::assertSame('Lyon_69001__45.764_4.8357_5000_25000', $pass2Query['locations'] ?? null);
        self::assertArrayNotHasKey('shipping_type', $pass2Query);

        // Union deduplicates overlapping list_id 102
        $ids = array_map(static fn ($dto): string => $dto->externalId, $results);
        self::assertSame(['101', '102', '103'], $ids);
    }

    public function testUnlimitedLimitCrawlsMultiplePagesUpToConfiguredCap(): void
    {
        $requestedPages = [];

        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedPages): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $page = isset($query['page']) ? (int) $query['page'] : 1;
            $requestedPages[] = $page;

            return new MockResponse($this->buildNextDataHtml([
                [
                    'list_id' => 2000 + $page,
                    'subject' => sprintf('Canon Printer Page %d', $page),
                    'price' => [45],
                    'url' => sprintf('https://www.leboncoin.fr/ad/%d', 2000 + $page),
                ],
            ], maxPages: 10));
        });

        $provider = $this->createProvider($httpClient, maxUnlimitedPages: 3);
        $results = $provider->search('canon printer ax380', 100.0, null, [
            'allow_shipping' => false,
        ]);

        self::assertSame([1, 2, 3], $requestedPages);
        self::assertCount(3, $results);
        self::assertSame(['2001', '2002', '2003'], array_map(static fn ($d): string => $d->externalId, $results));
    }

    private function createProvider(MockHttpClient $httpClient, int $maxUnlimitedPages = 15): LeBonCoinProvider
    {
        $geocoder = new FrenchGeocoderService($httpClient, new ArrayAdapter(), new NullLogger());

        return new LeBonCoinProvider(
            httpClient: $httpClient,
            logger: new NullLogger(),
            mockListingProvider: new MockListingProvider(),
            decoder: new JsonEncoder(),
            geocoder: $geocoder,
            fallbackToMock: false,
            maxUnlimitedPages: $maxUnlimitedPages,
        );
    }

    /**
     * @param list<array<string, mixed>> $ads
     */
    private function buildNextDataHtml(array $ads, int $maxPages): string
    {
        $payload = [
            'props' => [
                'pageProps' => [
                    'searchData' => [
                        'max_pages' => $maxPages,
                        'ads' => $ads,
                    ],
                ],
            ],
        ];

        return sprintf(
            '<!doctype html><html><body><script id="__NEXT_DATA__" type="application/json">%s</script></body></html>',
            (string) json_encode($payload),
        );
    }
}

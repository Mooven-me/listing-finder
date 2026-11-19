<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\FetchedListingDto;
use App\Dto\Provider\ListingDetailsDto;
use App\Dto\Provider\ProviderOptionDefinition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LeBonCoinProvider extends AbstractHttpListingProvider
{
    private const SEARCH_WEB_URL = 'https://www.leboncoin.fr/recherche';
    private const API_URL = 'https://api.leboncoin.fr/finder/search';
    private const SHIPPING_TYPES = 'mondial_relay,colissimo,chronopost,shop2shop,relais_colis';
    private const ITEMS_PER_PAGE = 35;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        MockListingProvider $mockListingProvider,
        DecoderInterface $decoder,
        private readonly FrenchGeocoderService $geocoder,
        #[Autowire(env: 'bool:LISTING_PROVIDER_FALLBACK_TO_MOCK')]
        bool $fallbackToMock = true,
        #[Autowire(env: 'int:LISTING_PROVIDER_MAX_UNLIMITED_PAGES')]
        int $maxUnlimitedPages = 15,
    ) {
        parent::__construct(
            $httpClient,
            $logger,
            $mockListingProvider,
            $decoder,
            $fallbackToMock,
            $maxUnlimitedPages,
        );
    }

    public function getName(): string
    {
        return 'leboncoin';
    }

    public function fetchListingDetails(string $url): ?ListingDetailsDto
    {
        $cleanUrl = trim($url);
        if ('' === $cleanUrl || !str_starts_with($cleanUrl, 'http')) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $cleanUrl, [
                'timeout' => 8.0,
                'headers' => $this->defaultHeaders(),
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $html = $response->getContent(false);
            if ('' === trim($html)) {
                return null;
            }

            $crawler = new Crawler($html, 'https://www.leboncoin.fr');
            $nextDataNode = $crawler->filter('script#__NEXT_DATA__');
            if (0 === $nextDataNode->count()) {
                return null;
            }

            /** @var mixed $decoded */
            $decoded = $this->decoder->decode($nextDataNode->text(''), 'json');
            if (!\is_array($decoded)) {
                return null;
            }

            $ad = $decoded['props']['pageProps']['ad']
                ?? $decoded['props']['pageProps']['initialProps']['ad']
                ?? null;

            if (!\is_array($ad)) {
                return null;
            }

            $body = isset($ad['body']) && \is_string($ad['body']) && '' !== trim($ad['body'])
                ? trim($ad['body'])
                : null;
            $categoryName = isset($ad['category_name']) && \is_string($ad['category_name']) && '' !== trim($ad['category_name'])
                ? trim($ad['category_name'])
                : null;
            $brand = isset($ad['brand']) && \is_string($ad['brand']) && '' !== trim($ad['brand'])
                ? trim($ad['brand'])
                : null;

            $condition = null;
            $attributes = [];
            if (isset($ad['attributes']) && \is_array($ad['attributes'])) {
                foreach ($ad['attributes'] as $attr) {
                    if (!\is_array($attr)) {
                        continue;
                    }
                    $key = isset($attr['key']) && \is_string($attr['key']) ? $attr['key'] : '';
                    $keyLabel = isset($attr['key_label']) && \is_string($attr['key_label']) ? trim($attr['key_label']) : '';
                    $valueLabel = isset($attr['value_label']) && \is_string($attr['value_label']) ? trim($attr['value_label']) : '';
                    $isGeneric = (bool) ($attr['generic'] ?? false);

                    if ('condition' === $key && '' !== $valueLabel) {
                        $condition = $valueLabel;
                    }

                    if ($isGeneric && '' !== $keyLabel && '' !== $valueLabel) {
                        $attributes[$keyLabel] = $valueLabel;
                    }
                }
            }

            $images = [];
            if (isset($ad['images']['urls']) && \is_array($ad['images']['urls'])) {
                foreach ($ad['images']['urls'] as $imgUrl) {
                    if (\is_string($imgUrl) && '' !== $imgUrl) {
                        $images[] = $imgUrl;
                    }
                }
            }

            return new ListingDetailsDto(
                description: $body,
                categoryName: $categoryName,
                brand: $brand,
                attributes: $attributes,
                conditionState: $condition,
                imageUrls: $images,
            );
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to fetch LeBonCoin ad details.', [
                'url' => $cleanUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<ProviderOptionDefinition>
     */
    public function getOptionDefinitions(): array
    {
        return [
            new ProviderOptionDefinition(
                name: 'sort',
                label: 'Search Ranking',
                type: ProviderOptionDefinition::TYPE_SELECT,
                default: 'relevance',
                choices: [
                    'relevance' => 'Relevance (Default)',
                    'newest' => 'Newest First',
                    'price_asc' => 'Price: Low to High',
                ],
                help: 'Matches LeBonCoin search bar relevance by default.',
            ),
            new ProviderOptionDefinition(
                name: 'allow_shipping',
                label: 'Allow Nationwide Shipping',
                type: ProviderOptionDefinition::TYPE_CHECKBOX,
                default: true,
                help: 'Fetch shippable ads across France (Mondial Relay, Colissimo, etc.).',
            ),
            new ProviderOptionDefinition(
                name: 'allow_near_me',
                label: 'Allow Near Me (Local Pickup)',
                type: ProviderOptionDefinition::TYPE_CHECKBOX,
                default: false,
                help: 'Combine with shipping to also include hand-delivery-only deals near your city.',
            ),
            new ProviderOptionDefinition(
                name: 'location',
                label: 'Location (City or Postal Code)',
                type: ProviderOptionDefinition::TYPE_TEXT,
                default: '',
                placeholder: 'e.g. Lyon, Vannes, 75001',
                dependsOn: 'allow_near_me',
            ),
            new ProviderOptionDefinition(
                name: 'radius_km',
                label: 'Radius (km)',
                type: ProviderOptionDefinition::TYPE_NUMBER,
                default: 30,
                placeholder: '30',
                dependsOn: 'allow_near_me',
                min: 5,
                max: 200,
            ),
            new ProviderOptionDefinition(
                name: 'search_in_title',
                label: 'Match Keywords in Title Only',
                type: ProviderOptionDefinition::TYPE_CHECKBOX,
                default: false,
                help: 'Reduces noise by requiring keywords in the ad subject.',
            ),
            new ProviderOptionDefinition(
                name: 'owner_type',
                label: 'Seller Type',
                type: ProviderOptionDefinition::TYPE_SELECT,
                default: 'all',
                choices: [
                    'all' => 'All Sellers',
                    'private' => 'Private Individuals Only',
                    'pro' => 'Professionals Only',
                ],
            ),
            new ProviderOptionDefinition(
                name: 'min_price',
                label: 'Min Price (EUR)',
                type: ProviderOptionDefinition::TYPE_NUMBER,
                default: null,
                placeholder: 'Optional (skip 1 EUR ads)',
                min: 1,
                max: 100000,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<FetchedListingDto>
     */
    public function search(string $query, ?float $maxPrice = null, ?int $limit = 25, array $options = []): array
    {
        $cleanQuery = trim($query);
        if ('' === $cleanQuery) {
            return [];
        }

        $opts = $this->normalizeOptions($options);
        $effectiveLimit = (null !== $limit && $limit > 0) ? $limit : null;

        try {
            $ssrSucceeded = false;
            $webListings = $this->searchViaWeb($cleanQuery, $maxPrice, $effectiveLimit, $opts, $ssrSucceeded);
            if ([] !== $webListings) {
                return $webListings;
            }

            if ($ssrSucceeded) {
                $this->logger->info('Provider {provider} SSR returned 0 listings for query "{query}".', [
                    'provider' => $this->getName(),
                    'query' => $cleanQuery,
                ]);

                return [];
            }

            // Fallback to JSON API endpoint ONLY if web SSR itself failed (non-200 / missing __NEXT_DATA__)
            $filters = [
                'keywords' => [
                    'text' => $cleanQuery,
                ],
            ];

            if (true === ($opts['search_in_title'] ?? false)) {
                $filters['keywords']['type'] = 'subject';
            }

            $priceRange = [];
            if (is_numeric($opts['min_price'] ?? null) && (float) $opts['min_price'] > 0.0) {
                $priceRange['min'] = (int) floor((float) $opts['min_price']);
            }
            if (null !== $maxPrice && $maxPrice > 0.0) {
                $priceRange['max'] = (int) ceil($maxPrice * 1.5);
            }
            if ([] !== $priceRange) {
                $filters['ranges'] = ['price' => $priceRange];
            }

            $response = $this->httpClient->request('POST', self::API_URL, [
                'timeout' => 8.0,
                'headers' => array_merge($this->defaultHeaders(), [
                    'Content-Type' => 'application/json',
                    'Origin' => 'https://www.leboncoin.fr',
                    'Referer' => 'https://www.leboncoin.fr/',
                ]),
                'json' => [
                    'limit' => $effectiveLimit ?? self::ITEMS_PER_PAGE,
                    'limit_alu' => 0,
                    'filters' => $filters,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $effectiveLimit, sprintf('HTTP status %d', $statusCode));
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
            $ads = $payload['ads'] ?? null;
            if (!\is_array($ads) || [] === $ads) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $effectiveLimit, 'Empty or missing ads array in response');
            }

            $listings = $this->parseAdsArray($ads, $effectiveLimit);
            if ([] === $listings) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $effectiveLimit, 'No valid ads parsed');
            }

            return $listings;
        } catch (\Throwable $e) {
            return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $effectiveLimit, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return list<FetchedListingDto>
     */
    private function searchViaWeb(string $cleanQuery, ?float $maxPrice, ?int $limit, array $opts, bool &$ssrSucceeded = false): array
    {
        $allowShipping = (bool) ($opts['allow_shipping'] ?? true);
        $allowNearMe = (bool) ($opts['allow_near_me'] ?? false);
        $locationQuery = is_string($opts['location'] ?? null) ? trim($opts['location']) : '';
        $radiusKm = is_numeric($opts['radius_km'] ?? null) ? max(5, min(200, (int) $opts['radius_km'])) : 30;

        $locationParam = null;
        if ($allowNearMe && '' !== $locationQuery) {
            $locationParam = $this->buildLocationParameter($locationQuery, $radiusKm);
        }

        // Build 1 or 2 query scopes:
        // - Shipping + Near Me both enabled => UNION (nationwide shipping OR local radius pickup)
        // - Shipping only => nationwide shipping_type=...
        // - Near Me only => locations=...
        // - Neither => nationwide unfiltered
        $passes = [];
        if ($allowShipping && null !== $locationParam) {
            $passes[] = ['shipping_type' => self::SHIPPING_TYPES];
            $passes[] = ['locations' => $locationParam];
        } elseif ($allowShipping) {
            $passes[] = ['shipping_type' => self::SHIPPING_TYPES];
        } elseif (null !== $locationParam) {
            $passes[] = ['locations' => $locationParam];
        } else {
            $passes[] = [];
        }

        $baseQueryParams = $this->buildBaseWebQueryParams($cleanQuery, $maxPrice, $opts);
        $listings = [];
        $seenIds = [];

        foreach ($passes as $passIndex => $passParams) {
            // When doing a UNION of 2 passes with a finite limit, split budget fairly if first pass fills it,
            // or allow local pass to also contribute up to half the limit when needed.
            $passLimit = null;
            if (null !== $limit) {
                if (2 === \count($passes) && 0 === $passIndex) {
                    $passLimit = max(1, (int) ceil($limit * 0.7));
                } else {
                    $remaining = $limit - \count($listings);
                    if ($remaining <= 0) {
                        break;
                    }
                    $passLimit = $remaining;
                }
            }

            $passResults = $this->crawlWebPages(
                array_merge($baseQueryParams, $passParams),
                $passLimit,
                $seenIds,
                $ssrSucceeded,
            );

            foreach ($passResults as $dto) {
                $listings[] = $dto;
                if (null !== $limit && \count($listings) >= $limit) {
                    break 2;
                }
            }
        }

        return $listings;
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return array<string, string>
     */
    private function buildBaseWebQueryParams(string $cleanQuery, ?float $maxPrice, array $opts): array
    {
        $params = [
            'text' => $cleanQuery,
        ];

        $sort = is_string($opts['sort'] ?? null) ? $opts['sort'] : 'relevance';
        if ('newest' === $sort) {
            $params['sort'] = 'time';
            $params['order'] = 'desc';
        } elseif ('price_asc' === $sort) {
            $params['sort'] = 'price';
            $params['order'] = 'asc';
        }
        // 'relevance' omits sort (uses LeBonCoin's native search relevance engine)

        if (true === ($opts['search_in_title'] ?? false)) {
            $params['search_in'] = 'subject';
        }

        $ownerType = is_string($opts['owner_type'] ?? null) ? $opts['owner_type'] : 'all';
        if (\in_array($ownerType, ['private', 'pro'], true)) {
            $params['owner_type'] = $ownerType;
        }

        $minStr = 'min';
        if (is_numeric($opts['min_price'] ?? null) && (float) $opts['min_price'] > 0.0) {
            $minStr = (string) (int) floor((float) $opts['min_price']);
        }

        $maxStr = 'max';
        if (null !== $maxPrice && $maxPrice > 0.0) {
            $maxStr = (string) (int) ceil($maxPrice * 1.5);
        }

        if ('min' !== $minStr || 'max' !== $maxStr) {
            $params['price'] = sprintf('%s-%s', $minStr, $maxStr);
        }

        return $params;
    }

    private function buildLocationParameter(string $locationQuery, int $radiusKm): string
    {
        $geo = $this->geocoder->resolve($locationQuery);
        if (null !== $geo) {
            $safeCity = str_replace([' ', ','], ['-', ''], $geo['city']);
            $label = '' !== $geo['postcode'] ? sprintf('%s_%s', $safeCity, $geo['postcode']) : $safeCity;
            $radiusMeters = $radiusKm * 1000;

            return sprintf('%s__%s_%s_5000_%d', $label, $geo['lat'], $geo['lng'], $radiusMeters);
        }

        // Fallback to raw city name / postal code if geocoding was unavailable
        return trim($locationQuery);
    }

    /**
     * @param array<string, string> $queryParams
     * @param array<string, bool>   $seenIds
     *
     * @return list<FetchedListingDto>
     */
    private function crawlWebPages(array $queryParams, ?int $limit, array &$seenIds, bool &$ssrSucceeded = false): array
    {
        $collected = [];
        $maxPages = $this->resolveMaxPages($limit, self::ITEMS_PER_PAGE);

        for ($page = 1; $page <= $maxPages; ++$page) {
            if (null !== $limit && \count($collected) >= $limit) {
                break;
            }

            if ($page > 1) {
                usleep(250_000);
            }

            $pageParams = $queryParams;
            if ($page > 1) {
                $pageParams['page'] = (string) $page;
            }

            $response = $this->httpClient->request('GET', self::SEARCH_WEB_URL, [
                'timeout' => 10.0,
                'headers' => $this->defaultHeaders(),
                'query' => $pageParams,
            ]);

            if (200 !== $response->getStatusCode()) {
                break;
            }

            $html = $response->getContent(false);
            if ('' === trim($html)) {
                break;
            }

            $crawler = new Crawler($html, 'https://www.leboncoin.fr');
            $nextDataNode = $crawler->filter('script#__NEXT_DATA__');
            if (0 === $nextDataNode->count()) {
                break;
            }

            try {
                /** @var mixed $decoded */
                $decoded = $this->decoder->decode($nextDataNode->text(''), 'json');
            } catch (\Throwable) {
                break;
            }

            if (!\is_array($decoded)) {
                break;
            }

            $searchData = $decoded['props']['pageProps']['searchData']
                ?? $decoded['props']['pageProps']['initialProps']['searchData']
                ?? null;

            if (!\is_array($searchData)) {
                break;
            }

            $ssrSucceeded = true;

            // Refine maxPages using LeBonCoin's reported max_pages on page 1
            if (1 === $page && isset($searchData['max_pages']) && is_numeric($searchData['max_pages'])) {
                $maxPages = $this->resolveMaxPages($limit, self::ITEMS_PER_PAGE, (int) $searchData['max_pages']);
            }

            $ads = $searchData['ads'] ?? null;
            if (!\is_array($ads) || [] === $ads) {
                break;
            }

            $remaining = null !== $limit ? ($limit - \count($collected)) : null;
            $pageParsed = $this->parseAdsArray($ads, $remaining, $seenIds);
            if ([] === $pageParsed) {
                break;
            }

            foreach ($pageParsed as $dto) {
                $collected[] = $dto;
            }
        }

        return $collected;
    }

    /**
     * @param array<int|string, mixed> $ads
     * @param array<string, bool>      $seenIds
     *
     * @return list<FetchedListingDto>
     */
    private function parseAdsArray(array $ads, ?int $limit, array &$seenIds = []): array
    {
        $listings = [];

        foreach ($ads as $ad) {
            if (null !== $limit && \count($listings) >= $limit) {
                break;
            }

            if (!\is_array($ad)) {
                continue;
            }

            $externalId = isset($ad['list_id']) ? (string) $ad['list_id'] : '';
            $title = isset($ad['subject']) ? trim((string) $ad['subject']) : '';
            if ('' === $externalId || '' === $title || isset($seenIds[$externalId])) {
                continue;
            }

            $priceValue = 0.0;
            if (isset($ad['price']) && \is_array($ad['price']) && isset($ad['price'][0])) {
                $priceValue = (float) $ad['price'][0];
            } elseif (isset($ad['price']) && is_numeric($ad['price'])) {
                $priceValue = (float) $ad['price'];
            }

            if ($priceValue <= 0.0) {
                continue;
            }

            $images = [];
            if (isset($ad['images']['urls']) && \is_array($ad['images']['urls'])) {
                foreach ($ad['images']['urls'] as $imgUrl) {
                    if (\is_string($imgUrl) && '' !== $imgUrl) {
                        $images[] = $imgUrl;
                    }
                }
            }

            $condition = null;
            if (isset($ad['attributes']) && \is_array($ad['attributes'])) {
                foreach ($ad['attributes'] as $attr) {
                    if (\is_array($attr) && isset($attr['key']) && 'condition' === $attr['key'] && isset($attr['value_label']) && \is_string($attr['value_label'])) {
                        $condition = $attr['value_label'];
                        break;
                    }
                }
            }

            $publishedAt = null;
            if (isset($ad['first_publication_date']) && \is_string($ad['first_publication_date'])) {
                try {
                    $publishedAt = new \DateTimeImmutable($ad['first_publication_date']);
                } catch (\Exception) {
                    $publishedAt = null;
                }
            }

            $seenIds[$externalId] = true;
            $listings[] = new FetchedListingDto(
                provider: $this->getName(),
                externalId: $externalId,
                title: $title,
                price: $priceValue,
                url: isset($ad['url']) && \is_string($ad['url']) ? $ad['url'] : sprintf('https://www.leboncoin.fr/ad/%s', $externalId),
                description: isset($ad['body']) && \is_string($ad['body']) && '' !== trim($ad['body']) ? trim($ad['body']) : null,
                currency: 'EUR',
                imageUrls: $images,
                conditionState: $condition,
                publishedAt: $publishedAt,
            );
        }

        return $listings;
    }
}

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

class VintedProvider extends AbstractHttpListingProvider
{
    private const CATALOG_API_URL = 'https://www.vinted.fr/api/v2/catalog/items';
    private const CATALOG_WEB_URL = 'https://www.vinted.fr/catalog';
    private const ITEMS_PER_PAGE = 96;
    private const MIN_REQUEST_INTERVAL_MS = 1000;
    private const BLOCK_COOLDOWN_SECONDS = 900;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        MockListingProvider $mockListingProvider,
        DecoderInterface $decoder,
        #[Autowire(env: 'bool:LISTING_PROVIDER_FALLBACK_TO_MOCK')]
        bool $fallbackToMock = true,
        #[Autowire(env: 'int:LISTING_PROVIDER_MAX_UNLIMITED_PAGES')]
        int $maxUnlimitedPages = 15,
        private readonly ?ProviderHealthService $providerHealth = null,
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
        return 'vinted';
    }

    public function fetchListingDetails(string $url): ?ListingDetailsDto
    {
        $cleanUrl = trim($url);
        if ('' === $cleanUrl || !str_starts_with($cleanUrl, 'http')) {
            return null;
        }

        if ($this->providerHealth?->isCoolingDown($this->getName())) {
            return new ListingDetailsDto(rateLimited: true);
        }

        try {
            $this->providerHealth?->throttle($this->getName(), self::MIN_REQUEST_INTERVAL_MS);

            $response = $this->httpClient->request('GET', $cleanUrl, [
                'timeout' => 8.0,
                'headers' => array_merge($this->defaultHeaders(), [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Referer' => 'https://www.vinted.fr/',
                ]),
            ]);

            $statusCode = $response->getStatusCode();
            if (\in_array($statusCode, [404, 410], true)) {
                return new ListingDetailsDto(isSoldOrUnavailable: true);
            }

            if (\in_array($statusCode, [403, 429], true)) {
                $this->providerHealth?->markBlocked($this->getName(), $statusCode, 'Rate-limit / anti-bot response on Vinted item page', self::BLOCK_COOLDOWN_SECONDS);

                return new ListingDetailsDto(rateLimited: true);
            }

            if (200 !== $statusCode) {
                return null;
            }

            $html = $response->getContent(false);
            if ('' === trim($html)) {
                return null;
            }

            $isSoldOrUnavailable = $this->isVintedItemSoldOrClosed($html);

            $description = null;
            if (1 === preg_match('/\\\\?"description\\\\?"\s*:\s*\\\\?"((?:[^"\\\\]|\\\\.)*)\\\\?"/u', $html, $descMatch)) {
                $decodedDesc = json_decode('"'.str_replace('\\"', '"', $descMatch[1]).'"');
                if (\is_string($decodedDesc) && '' !== trim($decodedDesc)) {
                    $description = trim($decodedDesc);
                }
            }

            $brand = null;
            if (1 === preg_match('/\\\\?"brand_title\\\\?"\s*:\s*\\\\?"((?:[^"\\\\]|\\\\.)*)\\\\?"/u', $html, $brandMatch)) {
                $decodedBrand = json_decode('"'.str_replace('\\"', '"', $brandMatch[1]).'"');
                if (\is_string($decodedBrand) && '' !== trim($decodedBrand)) {
                    $brand = trim($decodedBrand);
                }
            }

            if (null === $description) {
                $crawler = new Crawler($html, 'https://www.vinted.fr');
                $meta = $crawler->filter('meta[name="description"]');
                if ($meta->count() > 0) {
                    $metaContent = trim((string) $meta->attr('content'));
                    if ('' !== $metaContent) {
                        $description = $metaContent;
                    }
                }
            }

            return new ListingDetailsDto(
                description: $description,
                brand: $brand,
                isSoldOrUnavailable: $isSoldOrUnavailable,
            );
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to fetch Vinted item details.', [
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
                    'newest_first' => 'Newest First',
                    'price_low_to_high' => 'Price: Low to High',
                ],
                help: 'Matches Vinted catalog relevance ranking by default.',
            ),
            new ProviderOptionDefinition(
                name: 'min_condition',
                label: 'Minimum Condition',
                type: ProviderOptionDefinition::TYPE_SELECT,
                default: 'all',
                choices: [
                    'all' => 'All Conditions',
                    'good_plus' => 'Good or Better',
                    'very_good_plus' => 'Very Good or Better',
                    'new_only' => 'New Only',
                ],
            ),
            new ProviderOptionDefinition(
                name: 'exclude_sold',
                label: 'Exclude Sold / Reserved Ads',
                type: ProviderOptionDefinition::TYPE_CHECKBOX,
                default: true,
                help: 'Skips items flagged as closed, sold, or reserved.',
            ),
            new ProviderOptionDefinition(
                name: 'min_price',
                label: 'Min Price (EUR)',
                type: ProviderOptionDefinition::TYPE_NUMBER,
                default: null,
                placeholder: 'Optional (skip accessories)',
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

        if ($this->providerHealth?->isCoolingDown($this->getName())) {
            $this->logger->info('Skipping Vinted search for "{query}" while provider is in cooldown.', [
                'query' => $cleanQuery,
            ]);

            return [];
        }

        $opts = $this->normalizeOptions($options);
        $effectiveLimit = (null !== $limit && $limit > 0) ? $limit : null;

        $queryParams = [
            'search_text' => $cleanQuery,
            'order' => is_string($opts['sort'] ?? null) ? $opts['sort'] : 'relevance',
            'currency' => 'EUR',
        ];

        if (is_numeric($opts['min_price'] ?? null) && (float) $opts['min_price'] > 0.0) {
            $queryParams['price_from'] = (string) round((float) $opts['min_price'], 2);
        }

        if (null !== $maxPrice && $maxPrice > 0.0) {
            $queryParams['price_to'] = (string) round($maxPrice * 1.5, 2);
        }

        $statusIds = match ($opts['min_condition'] ?? 'all') {
            'new_only' => ['6', '1'],
            'very_good_plus' => ['6', '1', '2'],
            'good_plus' => ['6', '1', '2', '3'],
            default => [],
        };
        if ([] !== $statusIds) {
            $queryParams['status_ids'] = $statusIds;
        }

        try {
            // 1. Primary: Crawl Vinted SSR catalog HTML across pages
            $ssrSucceeded = false;
            $webListings = $this->crawlCatalogPages($queryParams, $effectiveLimit, $ssrSucceeded);
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

            // 2. Fallback to JSON API v2 ONLY if HTML crawl itself failed
            $apiQueryParams = $queryParams;
            $apiQueryParams['per_page'] = $effectiveLimit ?? 25;

            $response = $this->httpClient->request('GET', self::CATALOG_API_URL, [
                'timeout' => 8.0,
                'headers' => array_merge($this->defaultHeaders(), [
                    'Referer' => 'https://www.vinted.fr/',
                ]),
                'query' => $apiQueryParams,
            ]);

            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $effectiveLimit, sprintf('HTTP status %d', $statusCode));
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
            $items = $payload['items'] ?? null;
            if (!\is_array($items) || [] === $items) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $effectiveLimit, 'Empty or missing items array in response');
            }

            $slice = null !== $effectiveLimit ? \array_slice($items, 0, $effectiveLimit) : $items;
            $listings = [];
            foreach ($slice as $item) {
                if (!\is_array($item)) {
                    continue;
                }

                $externalId = isset($item['id']) ? (string) $item['id'] : '';
                $title = isset($item['title']) ? trim((string) $item['title']) : '';
                if ('' === $externalId || '' === $title) {
                    continue;
                }

                $priceValue = 0.0;
                if (isset($item['price']['amount']) && is_numeric($item['price']['amount'])) {
                    $priceValue = (float) $item['price']['amount'];
                } elseif (isset($item['price']) && is_numeric($item['price'])) {
                    $priceValue = (float) $item['price'];
                }

                $images = [];
                if (isset($item['photo']['url']) && \is_string($item['photo']['url'])) {
                    $images[] = $item['photo']['url'];
                }

                $listings[] = new FetchedListingDto(
                    provider: $this->getName(),
                    externalId: $externalId,
                    title: $title,
                    price: $priceValue,
                    url: isset($item['url']) && \is_string($item['url']) ? $item['url'] : sprintf('https://www.vinted.fr/items/%s', $externalId),
                    description: isset($item['description']) && \is_string($item['description']) ? $item['description'] : null,
                    currency: isset($item['price']['currency_code']) && \is_string($item['price']['currency_code']) ? $item['price']['currency_code'] : 'EUR',
                    imageUrls: $images,
                    conditionState: isset($item['status']) && \is_string($item['status']) ? $item['status'] : null,
                    publishedAt: new \DateTimeImmutable(),
                );
            }

            if ([] === $listings) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $effectiveLimit, 'No valid Vinted items parsed');
            }

            return $listings;
        } catch (\Throwable $e) {
            return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $effectiveLimit, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $queryParams
     *
     * @return list<FetchedListingDto>
     */
    private function crawlCatalogPages(array $queryParams, ?int $limit, bool &$ssrSucceeded = false): array
    {
        $collected = [];
        $seenIds = [];
        $maxPages = $this->resolveMaxPages($limit, self::ITEMS_PER_PAGE);

        for ($page = 1; $page <= $maxPages; ++$page) {
            if (null !== $limit && \count($collected) >= $limit) {
                break;
            }

            if ($this->providerHealth?->isCoolingDown($this->getName())) {
                break;
            }

            if (null !== $this->providerHealth) {
                $this->providerHealth->throttle($this->getName(), self::MIN_REQUEST_INTERVAL_MS);
            } elseif ($page > 1) {
                usleep(250_000);
            }

            $pageParams = $queryParams;
            if ($page > 1) {
                $pageParams['page'] = (string) $page;
            }

            $webResponse = $this->httpClient->request('GET', self::CATALOG_WEB_URL, [
                'timeout' => 10.0,
                'headers' => array_merge($this->defaultHeaders(), [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Referer' => 'https://www.vinted.fr/',
                ]),
                'query' => $pageParams,
            ]);

            $statusCode = $webResponse->getStatusCode();
            if (\in_array($statusCode, [403, 429], true)) {
                $this->providerHealth?->markBlocked($this->getName(), $statusCode, 'Rate-limit / anti-bot response on Vinted catalog', self::BLOCK_COOLDOWN_SECONDS);
                break;
            }

            if (200 !== $statusCode) {
                break;
            }

            $html = $webResponse->getContent(false);
            if ('' === trim($html)) {
                break;
            }

            if (1 === $page && (str_contains($html, '<body') || str_contains($html, '__next') || str_contains($html, 'catalog'))) {
                $ssrSucceeded = true;
            }

            $remaining = null !== $limit ? ($limit - \count($collected)) : null;
            $parsedFromHtml = $this->parseCatalogHtml($html, $remaining, $seenIds);
            if ([] === $parsedFromHtml) {
                break;
            }

            foreach ($parsedFromHtml as $dto) {
                $collected[] = $dto;
            }

            // If Vinted returned fewer items than a full page, there are no more pages
            if (\count($parsedFromHtml) < 20) {
                break;
            }
        }

        return $collected;
    }

    /**
     * @param array<string, bool> $seenIds
     *
     * @return list<FetchedListingDto>
     */
    private function parseCatalogHtml(string $html, ?int $limit, array &$seenIds = []): array
    {
        $crawler = new Crawler($html, 'https://www.vinted.fr');
        $listings = [];

        $crawler->filter('a[href*="/items/"]')->each(function (Crawler $node) use (&$listings, &$seenIds, $limit): void {
            if (null !== $limit && \count($listings) >= $limit) {
                return;
            }

            $href = trim((string) $node->attr('href'));
            if (1 !== preg_match('#/items/(\d+)#', $href, $idMatch)) {
                return;
            }

            $externalId = $idMatch[1];
            if (isset($seenIds[$externalId])) {
                return;
            }

            $rawTitleAttr = trim((string) $node->attr('title'));
            if ('' === $rawTitleAttr) {
                return;
            }

            // Format: "Title, Marque: Brand, État: Condition, 30.00 €, 32.20 €"
            $title = $rawTitleAttr;
            $brand = null;
            $condition = null;
            $price = 0.0;

            if (1 === preg_match('/(\d+(?:[.,]\d{1,2})?)\s*€/u', $rawTitleAttr, $priceMatch)) {
                $price = (float) str_replace(',', '.', $priceMatch[1]);
            }

            if (1 === preg_match('/[ÉE]tat:\s*([^,]+)/iu', $rawTitleAttr, $condMatch)) {
                $condition = trim($condMatch[1]);
            }

            if (1 === preg_match('/Marque:\s*([^,]+)/iu', $rawTitleAttr, $brandMatch)) {
                $brand = trim($brandMatch[1]);
            }

            $parts = preg_split('/,\s*(?:Marque|État|Etat|Taille):/iu', $rawTitleAttr);
            if (\is_array($parts) && isset($parts[0]) && '' !== trim($parts[0])) {
                $title = trim($parts[0]);
            }

            if ($price <= 0.0 || '' === $title) {
                return;
            }

            $images = [];
            $ancestors = $node->ancestors()->slice(0, 3);
            $ancestors->each(static function (Crawler $ancestor) use (&$images): void {
                if ([] !== $images) {
                    return;
                }
                $imgs = $ancestor->filter('img');
                if ($imgs->count() > 0) {
                    $src = trim((string) $imgs->first()->attr('src'));
                    if ('' !== $src) {
                        $images[] = $src;
                    }
                }
            });

            $cleanPath = strtok($href, '?') ?: $href;
            $url = str_starts_with($cleanPath, 'http') ? $cleanPath : 'https://www.vinted.fr'.$cleanPath;

            $descriptionParts = array_filter([
                $title,
                null !== $brand ? sprintf('Marque: %s', $brand) : null,
                null !== $condition ? sprintf('État: %s', $condition) : null,
            ]);

            $seenIds[$externalId] = true;
            $listings[] = new FetchedListingDto(
                provider: $this->getName(),
                externalId: $externalId,
                title: $title,
                price: $price,
                url: $url,
                description: implode(' | ', $descriptionParts),
                currency: 'EUR',
                imageUrls: $images,
                conditionState: $condition,
                publishedAt: new \DateTimeImmutable(),
            );
        });

        return $listings;
    }

    private function isVintedItemSoldOrClosed(string $html): bool
    {
        return 1 === preg_match('/\\\\?"is_closed\\\\?"\s*:\s*true/i', $html)
            || 1 === preg_match('/\\\\?"is_reserved\\\\?"\s*:\s*true/i', $html)
            || 1 === preg_match('/\\\\?"can_buy\\\\?"\s*:\s*false/i', $html);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\FetchedListingDto;
use Symfony\Component\DomCrawler\Crawler;

class LeBonCoinProvider extends AbstractHttpListingProvider
{
    private const SEARCH_WEB_URL = 'https://www.leboncoin.fr/recherche';
    private const API_URL = 'https://api.leboncoin.fr/finder/search';

    public function getName(): string
    {
        return 'leboncoin';
    }

    /**
     * @return list<FetchedListingDto>
     */
    public function search(string $query, ?float $maxPrice = null, int $limit = 25): array
    {
        $cleanQuery = trim($query);
        if ('' === $cleanQuery) {
            return [];
        }

        try {
            // 1. Primary: SSR Next.js search page (__NEXT_DATA__ JSON)
            $webListings = $this->searchViaWeb($cleanQuery, $maxPrice, $limit);
            if ([] !== $webListings) {
                return $webListings;
            }

            // 2. Fallback: API endpoint
            $filters = [
                'keywords' => [
                    'text' => $cleanQuery,
                ],
            ];

            if (null !== $maxPrice && $maxPrice > 0.0) {
                $filters['ranges'] = [
                    'price' => [
                        'max' => (int) ceil($maxPrice * 1.5),
                    ],
                ];
            }

            $response = $this->httpClient->request('POST', self::API_URL, [
                'timeout' => 8.0,
                'headers' => array_merge($this->defaultHeaders(), [
                    'Content-Type' => 'application/json',
                    'Origin' => 'https://www.leboncoin.fr',
                    'Referer' => 'https://www.leboncoin.fr/',
                ]),
                'json' => [
                    'limit' => $limit,
                    'limit_alu' => 0,
                    'filters' => $filters,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, sprintf('HTTP status %d', $statusCode));
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
            $ads = $payload['ads'] ?? null;
            if (!is_array($ads) || [] === $ads) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, 'Empty or missing ads array in response');
            }

            $listings = $this->parseAdsArray($ads, $limit);
            if ([] === $listings) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, 'No valid ads parsed');
            }

            return $listings;
        } catch (\Throwable $e) {
            return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, $e->getMessage());
        }
    }

    /**
     * @return list<FetchedListingDto>
     */
    private function searchViaWeb(string $cleanQuery, ?float $maxPrice, int $limit): array
    {
        $listings = [];
        $seenIds = [];
        $maxPages = max(1, min(3, (int) ceil($limit / 35)));

        for ($page = 1; $page <= $maxPages && \count($listings) < $limit; ++$page) {
            $queryParams = [
                'text' => $cleanQuery,
                'sort' => 'time',
                'order' => 'desc',
            ];

            if (null !== $maxPrice && $maxPrice > 0.0) {
                $queryParams['price'] = sprintf('min-%d', (int) ceil($maxPrice * 1.5));
            }

            if ($page > 1) {
                $queryParams['page'] = (string) $page;
            }

            $response = $this->httpClient->request('GET', self::SEARCH_WEB_URL, [
                'timeout' => 10.0,
                'headers' => $this->defaultHeaders(),
                'query' => $queryParams,
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

            $ads = $decoded['props']['pageProps']['searchData']['ads']
                ?? $decoded['props']['pageProps']['initialProps']['searchData']['ads']
                ?? null;

            if (!\is_array($ads) || [] === $ads) {
                break;
            }

            $pageParsed = $this->parseAdsArray($ads, $limit - \count($listings), $seenIds);
            if ([] === $pageParsed) {
                break;
            }

            foreach ($pageParsed as $dto) {
                $listings[] = $dto;
            }
        }

        return $listings;
    }

    /**
     * @param array<int|string, mixed> $ads
     * @param array<string, bool>      $seenIds
     *
     * @return list<FetchedListingDto>
     */
    private function parseAdsArray(array $ads, int $limit, array &$seenIds = []): array
    {
        $listings = [];

        foreach ($ads as $ad) {
            if (\count($listings) >= $limit) {
                break;
            }

            if (!is_array($ad)) {
                continue;
            }

            $externalId = isset($ad['list_id']) ? (string) $ad['list_id'] : '';
            $title = isset($ad['subject']) ? trim((string) $ad['subject']) : '';
            if ('' === $externalId || '' === $title || isset($seenIds[$externalId])) {
                continue;
            }

            $priceValue = 0.0;
            if (isset($ad['price']) && is_array($ad['price']) && isset($ad['price'][0])) {
                $priceValue = (float) $ad['price'][0];
            } elseif (isset($ad['price']) && is_numeric($ad['price'])) {
                $priceValue = (float) $ad['price'];
            }

            if ($priceValue <= 0.0) {
                continue;
            }

            $images = [];
            if (isset($ad['images']['urls']) && is_array($ad['images']['urls'])) {
                foreach ($ad['images']['urls'] as $imgUrl) {
                    if (is_string($imgUrl) && '' !== $imgUrl) {
                        $images[] = $imgUrl;
                    }
                }
            }

            $condition = null;
            if (isset($ad['attributes']) && is_array($ad['attributes'])) {
                foreach ($ad['attributes'] as $attr) {
                    if (is_array($attr) && isset($attr['key']) && 'condition' === $attr['key'] && isset($attr['value_label']) && is_string($attr['value_label'])) {
                        $condition = $attr['value_label'];
                        break;
                    }
                }
            }

            $publishedAt = null;
            if (isset($ad['first_publication_date']) && is_string($ad['first_publication_date'])) {
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
                url: isset($ad['url']) && is_string($ad['url']) ? $ad['url'] : sprintf('https://www.leboncoin.fr/ad/%s', $externalId),
                description: isset($ad['body']) && is_string($ad['body']) ? $ad['body'] : null,
                currency: 'EUR',
                imageUrls: $images,
                conditionState: $condition,
                publishedAt: $publishedAt,
            );
        }

        return $listings;
    }
}

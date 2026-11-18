<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\FetchedListingDto;

class VintedProvider extends AbstractHttpListingProvider
{
    private const CATALOG_URL = 'https://www.vinted.fr/api/v2/catalog/items';

    public function getName(): string
    {
        return 'vinted';
    }

    /**
     * @return list<FetchedListingDto>
     */
    public function search(string $query, ?float $maxPrice = null, int $limit = 5): array
    {
        $cleanQuery = trim($query);
        if ('' === $cleanQuery) {
            return [];
        }

        $queryParams = [
            'search_text' => $cleanQuery,
            'per_page' => $limit,
            'order' => 'newest_first',
        ];

        if (null !== $maxPrice && $maxPrice > 0.0) {
            $queryParams['price_to'] = (string) round($maxPrice * 1.5, 2);
            $queryParams['currency'] = 'EUR';
        }

        try {
            $response = $this->httpClient->request('GET', self::CATALOG_URL, [
                'timeout' => 8.0,
                'headers' => array_merge($this->defaultHeaders(), [
                    'Referer' => 'https://www.vinted.fr/',
                ]),
                'query' => $queryParams,
            ]);

            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, sprintf('HTTP status %d', $statusCode));
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
            $items = $payload['items'] ?? null;
            if (!is_array($items) || [] === $items) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, 'Empty or missing items array in response');
            }

            $listings = [];
            foreach (array_slice($items, 0, $limit) as $item) {
                if (!is_array($item)) {
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
                if (isset($item['photo']['url']) && is_string($item['photo']['url'])) {
                    $images[] = $item['photo']['url'];
                }

                $listings[] = new FetchedListingDto(
                    provider: $this->getName(),
                    externalId: $externalId,
                    title: $title,
                    price: $priceValue,
                    url: isset($item['url']) && is_string($item['url']) ? $item['url'] : sprintf('https://www.vinted.fr/items/%s', $externalId),
                    description: isset($item['description']) && is_string($item['description']) ? $item['description'] : null,
                    currency: isset($item['price']['currency_code']) && is_string($item['price']['currency_code']) ? $item['price']['currency_code'] : 'EUR',
                    imageUrls: $images,
                    conditionState: isset($item['status']) && is_string($item['status']) ? $item['status'] : null,
                    publishedAt: new \DateTimeImmutable(),
                );
            }

            if ([] === $listings) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, 'No valid Vinted items parsed');
            }

            return $listings;
        } catch (\Throwable $e) {
            return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, $e->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\FetchedListingDto;

class LeBonCoinProvider extends AbstractHttpListingProvider
{
    private const API_URL = 'https://api.leboncoin.fr/finder/search';

    public function getName(): string
    {
        return 'leboncoin';
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

        try {
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

            $listings = [];
            foreach (array_slice($ads, 0, $limit) as $ad) {
                if (!is_array($ad)) {
                    continue;
                }

                $externalId = isset($ad['list_id']) ? (string) $ad['list_id'] : '';
                $title = isset($ad['subject']) ? trim((string) $ad['subject']) : '';
                if ('' === $externalId || '' === $title) {
                    continue;
                }

                $priceValue = 0.0;
                if (isset($ad['price']) && is_array($ad['price']) && isset($ad['price'][0])) {
                    $priceValue = (float) $ad['price'][0];
                } elseif (isset($ad['price']) && is_numeric($ad['price'])) {
                    $priceValue = (float) $ad['price'];
                }

                $images = [];
                if (isset($ad['images']['urls']) && is_array($ad['images']['urls'])) {
                    foreach ($ad['images']['urls'] as $imgUrl) {
                        if (is_string($imgUrl) && '' !== $imgUrl) {
                            $images[] = $imgUrl;
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

                $listings[] = new FetchedListingDto(
                    provider: $this->getName(),
                    externalId: $externalId,
                    title: $title,
                    price: $priceValue,
                    url: isset($ad['url']) && is_string($ad['url']) ? $ad['url'] : sprintf('https://www.leboncoin.fr/ad/%s', $externalId),
                    description: isset($ad['body']) && is_string($ad['body']) ? $ad['body'] : null,
                    currency: 'EUR',
                    imageUrls: $images,
                    conditionState: null,
                    publishedAt: $publishedAt,
                );
            }

            if ([] === $listings) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, 'No valid ads parsed');
            }

            return $listings;
        } catch (\Throwable $e) {
            return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, $e->getMessage());
        }
    }
}

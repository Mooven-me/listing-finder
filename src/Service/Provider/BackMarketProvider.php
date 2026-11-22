<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\FetchedListingDto;
use Symfony\Component\DomCrawler\Crawler;

class BackMarketProvider extends AbstractHttpListingProvider
{
    private const SEARCH_URL = 'https://www.backmarket.fr/fr-fr/search';

    public function getName(): string
    {
        return 'backmarket';
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

        $effectiveLimit = (null !== $limit && $limit > 0) ? $limit : 50;

        try {
            $response = $this->httpClient->request('GET', self::SEARCH_URL, [
                'timeout' => 8.0,
                'headers' => $this->defaultHeaders(),
                'query' => [
                    'q' => $cleanQuery,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, sprintf('HTTP status %d', $statusCode));
            }

            $html = $response->getContent(false);
            if ('' === trim($html)) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, 'Empty HTML body');
            }

            $crawler = new Crawler($html, self::SEARCH_URL);
            $listings = [];

            $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$listings, $effectiveLimit): void {
                if (count($listings) >= $effectiveLimit) {
                    return;
                }

                try {
                    $decoded = $this->decoder->decode($node->text(''), 'json');
                } catch (\Throwable) {
                    return;
                }
                if (!is_array($decoded)) {
                    return;
                }

                $items = $decoded['itemListElement'] ?? [];
                if (!is_array($items)) {
                    return;
                }

                foreach ($items as $element) {
                    if (count($listings) >= $effectiveLimit || !is_array($element)) {
                        continue;
                    }

                    $product = $element['item'] ?? $element;
                    if (!is_array($product)) {
                        continue;
                    }

                    $title = isset($product['name']) && is_string($product['name']) ? trim($product['name']) : '';
                    $url = isset($product['url']) && is_string($product['url']) ? trim($product['url']) : '';
                    $price = isset($product['offers']['price']) && is_numeric($product['offers']['price'])
                        ? (float) $product['offers']['price']
                        : 0.0;

                    if ('' === $title || '' === $url || $price <= 0.0) {
                        continue;
                    }

                    $externalId = sha1($url);
                    $images = [];
                    if (isset($product['image']) && is_string($product['image'])) {
                        $images[] = $product['image'];
                    }

                    $listings[] = new FetchedListingDto(
                        provider: $this->getName(),
                        externalId: $externalId,
                        title: $title,
                        price: $price,
                        url: $url,
                        description: isset($product['description']) && is_string($product['description']) ? $product['description'] : null,
                        currency: 'EUR',
                        imageUrls: $images,
                        conditionState: 'Reconditionne',
                        publishedAt: new \DateTimeImmutable(),
                    );
                }
            });

            if ([] === $listings) {
                return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, 'No JSON-LD product items parsed');
            }

            return $listings;
        } catch (\Throwable $e) {
            return $this->fallbackOrEmpty($cleanQuery, $maxPrice, $limit, $e->getMessage());
        }
    }
}

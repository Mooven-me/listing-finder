<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\FetchedListingDto;
use Symfony\Component\DomCrawler\Crawler;

class VintedProvider extends AbstractHttpListingProvider
{
    private const CATALOG_API_URL = 'https://www.vinted.fr/api/v2/catalog/items';
    private const CATALOG_WEB_URL = 'https://www.vinted.fr/catalog';

    public function getName(): string
    {
        return 'vinted';
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

        $queryParams = [
            'search_text' => $cleanQuery,
            'order' => 'newest_first',
        ];

        if (null !== $maxPrice && $maxPrice > 0.0) {
            $queryParams['price_to'] = (string) round($maxPrice * 1.5, 2);
            $queryParams['currency'] = 'EUR';
        }

        try {
            // 1. Try HTML catalog page first (works reliably without session/cookie gates)
            $webResponse = $this->httpClient->request('GET', self::CATALOG_WEB_URL, [
                'timeout' => 10.0,
                'headers' => array_merge($this->defaultHeaders(), [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Referer' => 'https://www.vinted.fr/',
                ]),
                'query' => $queryParams,
            ]);

            if (200 === $webResponse->getStatusCode()) {
                $html = $webResponse->getContent(false);
                if ('' !== trim($html)) {
                    $parsedFromHtml = $this->parseCatalogHtml($html, $limit);
                    if ([] !== $parsedFromHtml) {
                        return $parsedFromHtml;
                    }
                }
            }

            // 2. Fallback to JSON API v2 if HTML returned no items
            $apiQueryParams = $queryParams;
            $apiQueryParams['per_page'] = $limit;

            $response = $this->httpClient->request('GET', self::CATALOG_API_URL, [
                'timeout' => 8.0,
                'headers' => array_merge($this->defaultHeaders(), [
                    'Referer' => 'https://www.vinted.fr/',
                ]),
                'query' => $apiQueryParams,
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

    /**
     * @return list<FetchedListingDto>
     */
    private function parseCatalogHtml(string $html, int $limit): array
    {
        $crawler = new Crawler($html, 'https://www.vinted.fr');
        $listings = [];
        $seenIds = [];

        $crawler->filter('a[href*="/items/"]')->each(function (Crawler $node) use (&$listings, &$seenIds, $limit): void {
            if (\count($listings) >= $limit) {
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
}

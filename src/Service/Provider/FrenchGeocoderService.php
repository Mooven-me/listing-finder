<?php

declare(strict_types=1);

namespace App\Service\Provider;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FrenchGeocoderService
{
    private const API_URL = 'https://api-adresse.data.gouv.fr/search/';

    /** @var array<string, array{city: string, postcode: string, lat: float, lng: float}|null> */
    private array $memoryCache = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolves a French city name or postal code to coordinates using data.gouv.fr.
     *
     * @return array{city: string, postcode: string, lat: float, lng: float}|null
     */
    public function resolve(string $query): ?array
    {
        $clean = trim($query);
        if ('' === $clean) {
            return null;
        }

        $cacheKey = 'fr_geo_'.sha1(mb_strtolower($clean));
        if (\array_key_exists($cacheKey, $this->memoryCache)) {
            return $this->memoryCache[$cacheKey];
        }

        try {
            /** @var array{city: string, postcode: string, lat: float, lng: float}|null $result */
            $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($clean): ?array {
                $item->expiresAfter(86400 * 7);

                $response = $this->httpClient->request('GET', self::API_URL, [
                    'timeout' => 5.0,
                    'query' => [
                        'q' => $clean,
                        'type' => 'municipality',
                        'limit' => 1,
                    ],
                ]);

                if (200 !== $response->getStatusCode()) {
                    return null;
                }

                /** @var array<string, mixed> $payload */
                $payload = $response->toArray(false);
                $feature = $payload['features'][0] ?? null;
                if (!\is_array($feature)) {
                    // Retry without municipality type restriction (e.g. street/postal code)
                    $fallbackResponse = $this->httpClient->request('GET', self::API_URL, [
                        'timeout' => 5.0,
                        'query' => [
                            'q' => $clean,
                            'limit' => 1,
                        ],
                    ]);
                    if (200 !== $fallbackResponse->getStatusCode()) {
                        return null;
                    }
                    /** @var array<string, mixed> $fallbackPayload */
                    $fallbackPayload = $fallbackResponse->toArray(false);
                    $feature = $fallbackPayload['features'][0] ?? null;
                }

                if (!\is_array($feature)) {
                    return null;
                }

                $coords = $feature['geometry']['coordinates'] ?? null;
                $props = $feature['properties'] ?? [];
                if (!\is_array($coords) || \count($coords) < 2 || !\is_numeric($coords[0]) || !\is_numeric($coords[1])) {
                    return null;
                }

                $city = is_string($props['city'] ?? null) && '' !== trim($props['city'])
                    ? trim($props['city'])
                    : (is_string($props['label'] ?? null) ? trim($props['label']) : $clean);
                $postcode = is_string($props['postcode'] ?? null) ? trim($props['postcode']) : '';

                return [
                    'city' => $city,
                    'postcode' => $postcode,
                    'lat' => round((float) $coords[1], 5),
                    'lng' => round((float) $coords[0], 5),
                ];
            });

            $this->memoryCache[$cacheKey] = $result;

            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning('Geocoding failed for location "{location}": {error}', [
                'location' => $clean,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

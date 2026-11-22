<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Service\Provider\ProviderHealthService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Domain-agnostic product specification lookup backed by a self-hosted SearXNG instance.
 *
 * When a listing title names a specific product model/code, queries SearXNG JSON search
 * (cached for 7 days, with 24h negative caching and polite 2s outbound throttling) so the
 * AI evaluator receives factual datasheet snippets instead of guessing.
 */
class SearxngProductSpecService
{
    private const POSITIVE_CACHE_TTL_SECONDS = 604800; // 7 days
    private const NEGATIVE_CACHE_TTL_SECONDS = 86400;  // 24 hours
    private const NEGATIVE_SENTINEL = '__SEARXNG_NO_RESULTS__';
    private const MAX_SNIPPET_CHARS = 1000;
    private const MIN_OUTBOUND_INTERVAL_MS = 2000;

    /** @var list<string> */
    private const NOISE_WORDS = [
        'vend', 'vends', 'vente', 'lot', 'neuf', 'neuve', 'occasion', 'bon', 'tres', 'très',
        'etat', 'état', 'parfait', 'comme', 'avec', 'sans', 'pour', 'dans', 'chez', 'prix',
        'ferme', 'urgent', 'negociable', 'négociable', 'envoi', 'possible', 'boite', 'boîte',
        'cable', 'câble', 'alimentation', 'chargeur', 'notice', 'facture', 'garantie',
        'dispo', 'unite', 'unité', 'original', 'version',
        'for', 'sale', 'used', 'new', 'good', 'condition', 'working', 'tested', 'with', 'without',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'SEARXNG_BASE_URL')]
        private readonly string $baseUrl = '',
        #[Autowire(env: 'int:SEARXNG_TIMEOUT')]
        private readonly int $timeout = 8,
        private readonly ?ProviderHealthService $providerHealth = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->baseUrl);
    }

    /**
     * Looks up factual product specifications for a listing title when it appears
     * to identify a specific product model/code.
     */
    public function lookupSpecsForTitle(string $listingTitle): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $lookupQuery = $this->extractModelLookupQuery($listingTitle);
        if (null === $lookupQuery) {
            return null;
        }

        $cacheKey = 'searxng_spec_v2_'.sha1(mb_strtolower($lookupQuery));

        try {
            /** @var string $cached */
            $cached = $this->cache->get($cacheKey, function (ItemInterface $item) use ($lookupQuery): string {
                $this->providerHealth?->throttle('searxng', self::MIN_OUTBOUND_INTERVAL_MS);

                $result = $this->querySearxng($lookupQuery);
                if (null === $result) {
                    $item->expiresAfter(self::NEGATIVE_CACHE_TTL_SECONDS);

                    return self::NEGATIVE_SENTINEL;
                }

                $item->expiresAfter(self::POSITIVE_CACHE_TTL_SECONDS);

                return $result;
            });

            return self::NEGATIVE_SENTINEL === $cached ? null : $cached;
        } catch (\Throwable $e) {
            $this->logger->debug('SearXNG spec lookup cache/query failed.', [
                'query' => $lookupQuery,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extracts a compact canonical model lookup query (up to 4 tokens centered on the model code)
     * only when the title contains a concrete alphanumeric model/product code.
     */
    public function extractModelLookupQuery(string $listingTitle): ?string
    {
        $cleanTitle = trim((string) preg_replace('/\s+/', ' ', $listingTitle));
        if ('' === $cleanTitle) {
            return null;
        }

        $rawTokens = preg_split('/[\s,\/;|()\[\]]+/', $cleanTitle) ?: [];
        $meaningful = [];
        $modelCodeIndex = null;

        foreach ($rawTokens as $rawToken) {
            $token = trim($rawToken, ".,:!?\"'`~*–—-");
            if (mb_strlen($token) < 2) {
                continue;
            }

            $lower = mb_strtolower($token);
            if (\in_array($lower, self::NOISE_WORDS, true)) {
                continue;
            }

            if (null === $modelCodeIndex && $this->looksLikeProductModelCode($token)) {
                $modelCodeIndex = \count($meaningful);
            }

            $meaningful[] = $token;
        }

        if (null === $modelCodeIndex || [] === $meaningful) {
            return null;
        }

        // Take up to 2 preceding tokens (product/brand) + model code + up to 1 following token (max 4 tokens)
        $start = max(0, $modelCodeIndex - 2);
        $selected = \array_slice($meaningful, $start, 4);

        return implode(' ', $selected);
    }

    /**
     * Domain-agnostic heuristic to identify concrete model identifiers (e.g. FS605v3, GS105Ev2,
     * LGS326, OS6350-P24, AX380, WH-1000XM5) while excluding generic measurements/units.
     */
    private function looksLikeProductModelCode(string $token): bool
    {
        if (1 !== preg_match('/^(?=.*[a-zA-Z])(?=.*\d)[a-zA-Z0-9._-]{3,24}$/', $token)) {
            return false;
        }

        if (1 === preg_match('/^\d+(?:[.,]\d+)?(?:gbps|mbps|kbps|gb|go|mb|mo|tb|to|ghz|mhz|hz|w|kw|v|mv|a|ma|mah|wh|mm|cm|m|km|kg|g|mg|ml|cl|l|oz|lb|in|ft|px|mp|fps|db|rpm|x|p|k|st|nd|rd|th)$/i', $token)) {
            return false;
        }

        if (1 === preg_match('/^(?:cat\d+[a-z]?|rj\d+|usb\d+[a-z]?|wifi\d+[a-z]?|hdmi\d+[a-z]?|ps\d+|gddr\d+[a-z]?|ddr\d+[a-z]?|pcie\d+)$/i', $token)) {
            return false;
        }

        return 1 === preg_match('/^[a-zA-Z]{1,6}[-._]?\d+[a-zA-Z0-9._-]*$/', $token)
            || 1 === preg_match('/^\d{3,5}[a-zA-Z]{1,4}[a-zA-Z0-9._-]*$/', $token);
    }

    private function querySearxng(string $lookupQuery): ?string
    {
        $endpoint = rtrim($this->baseUrl, '/').'/search';

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'timeout' => max(2, $this->timeout),
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'ListingFinder/1.0',
                ],
                'query' => [
                    'q' => sprintf('%s specifications', $lookupQuery),
                    'format' => 'json',
                    'safesearch' => '0',
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                $this->logger->info('SearXNG spec lookup returned non-200 status.', [
                    'status' => $response->getStatusCode(),
                    'query' => $lookupQuery,
                ]);

                return null;
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
            $results = $payload['results'] ?? null;
            if (!\is_array($results) || [] === $results) {
                return null;
            }

            $lines = [];
            $totalChars = 0;

            foreach (\array_slice($results, 0, 5) as $result) {
                if (!\is_array($result)) {
                    continue;
                }

                $title = isset($result['title']) && \is_string($result['title']) ? trim((string) preg_replace('/\s+/', ' ', $result['title'])) : '';
                $content = isset($result['content']) && \is_string($result['content']) ? trim((string) preg_replace('/\s+/', ' ', $result['content'])) : '';

                if ('' === $title && '' === $content) {
                    continue;
                }

                $line = '' !== $content
                    ? sprintf('- %s: %s', $title, mb_strimwidth($content, 0, 240, '...'))
                    : sprintf('- %s', $title);

                if ($totalChars + mb_strlen($line) > self::MAX_SNIPPET_CHARS && [] !== $lines) {
                    break;
                }

                $lines[] = $line;
                $totalChars += mb_strlen($line);

                if (\count($lines) >= 4) {
                    break;
                }
            }

            if ([] === $lines) {
                return null;
            }

            return sprintf("Search query: \"%s\"\n%s", $lookupQuery, implode("\n", $lines));
        } catch (\Throwable $e) {
            $this->logger->debug('SearXNG HTTP request failed.', [
                'endpoint' => $endpoint,
                'query' => $lookupQuery,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

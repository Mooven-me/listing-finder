<?php

declare(strict_types=1);

namespace App\Service\Provider;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tracks per-provider anti-bot / rate-limit cooldowns and enforces polite inter-request pacing
 * so marketplace providers are never hammered when returning HTTP 403/429.
 */
class ProviderHealthService
{
    private const BLOCK_KEY_PREFIX = 'provider_health_block_';
    private const PACE_KEY_PREFIX = 'provider_health_last_req_';

    /** @var array<string, float> */
    private array $lastRequestAt = [];

    /** @var array<string, array{provider: string, status_code: int, reason: string, blocked_until_ts: int, blocked_until_iso: string}> */
    private array $blockedMemo = [];

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isCoolingDown(string $provider): bool
    {
        return null !== $this->getBlockInfo($provider);
    }

    /**
     * @return array{provider: string, status_code: int, reason: string, blocked_until_ts: int, blocked_until_iso: string}|null
     */
    public function getBlockInfo(string $provider): ?array
    {
        $key = strtolower(trim($provider));
        $now = time();

        if (isset($this->blockedMemo[$key])) {
            if ($this->blockedMemo[$key]['blocked_until_ts'] > $now) {
                return $this->blockedMemo[$key];
            }
            unset($this->blockedMemo[$key]);
        }

        try {
            $cacheKey = self::BLOCK_KEY_PREFIX.sha1($key);
            /** @var array{provider: string, status_code: int, reason: string, blocked_until_ts: int, blocked_until_iso: string}|string $value */
            $value = $this->cache->get($cacheKey, static function (ItemInterface $item): string {
                $item->expiresAfter(1);

                return '__OK__';
            });

            if (\is_array($value) && ($value['blocked_until_ts'] ?? 0) > $now) {
                $this->blockedMemo[$key] = $value;

                return $value;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public function markBlocked(string $provider, int $statusCode, string $reason, int $ttlSeconds = 900): void
    {
        $key = strtolower(trim($provider));
        $ttl = max(60, $ttlSeconds);
        $untilTs = time() + $ttl;
        $info = [
            'provider' => $key,
            'status_code' => $statusCode,
            'reason' => $reason,
            'blocked_until_ts' => $untilTs,
            'blocked_until_iso' => (new \DateTimeImmutable('@'.$untilTs))->format(\DateTimeInterface::ATOM),
        ];

        $this->blockedMemo[$key] = $info;

        try {
            $cacheKey = self::BLOCK_KEY_PREFIX.sha1($key);
            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, static function (ItemInterface $item) use ($info, $ttl): array {
                $item->expiresAfter($ttl);

                return $info;
            });
        } catch (\Throwable) {
            // Keep in-memory memo even if cache backend fails
        }

        $this->logger->warning('Provider {provider} paused for {ttl}s after HTTP {status}: {reason}', [
            'provider' => $key,
            'ttl' => $ttl,
            'status' => $statusCode,
            'reason' => $reason,
        ]);
    }

    public function clearBlock(string $provider): void
    {
        $key = strtolower(trim($provider));
        unset($this->blockedMemo[$key]);

        try {
            $this->cache->delete(self::BLOCK_KEY_PREFIX.sha1($key));
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Enforces a minimum delay between outbound HTTP requests for a given provider.
     */
    public function throttle(string $provider, int $minIntervalMs): void
    {
        if ($minIntervalMs <= 0) {
            return;
        }

        $key = strtolower(trim($provider));
        $now = microtime(true);
        $last = $this->lastRequestAt[$key] ?? 0.0;

        try {
            $cacheKey = self::PACE_KEY_PREFIX.sha1($key);
            /** @var float $cachedLast */
            $cachedLast = $this->cache->get($cacheKey, static function (ItemInterface $item): float {
                $item->expiresAfter(60);

                return 0.0;
            });
            $last = max($last, $cachedLast);
        } catch (\Throwable) {
            // fallback to in-memory timestamp
        }

        $elapsedMs = ($now - $last) * 1000.0;
        if ($last > 0.0 && $elapsedMs < $minIntervalMs) {
            $sleepUs = (int) round(($minIntervalMs - $elapsedMs) * 1000.0);
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $recorded = microtime(true);
        $this->lastRequestAt[$key] = $recorded;

        try {
            $cacheKey = self::PACE_KEY_PREFIX.sha1($key);
            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, static function (ItemInterface $item) use ($recorded): float {
                $item->expiresAfter(60);

                return $recorded;
            });
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Returns active cooldown alerts for a list of providers (for dashboard & status API).
     *
     * @param list<string> $providers
     *
     * @return list<array{provider: string, status_code: int, reason: string, blocked_until_ts: int, blocked_until_iso: string}>
     */
    public function getActiveBlocks(array $providers): array
    {
        $blocks = [];
        foreach ($providers as $provider) {
            $info = $this->getBlockInfo($provider);
            if (null !== $info) {
                $blocks[] = $info;
            }
        }

        return $blocks;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\FetchedListingDto;
use App\Dto\Provider\ProviderOptionDefinition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AbstractHttpListingProvider implements ListingProviderInterface
{
    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly LoggerInterface $logger,
        protected readonly MockListingProvider $mockListingProvider,
        protected readonly DecoderInterface $decoder,
        #[Autowire(env: 'bool:LISTING_PROVIDER_FALLBACK_TO_MOCK')]
        protected bool $fallbackToMock = true,
        #[Autowire(env: 'int:LISTING_PROVIDER_MAX_UNLIMITED_PAGES')]
        protected int $maxUnlimitedPages = 15,
    ) {
    }

    /**
     * @return list<ProviderOptionDefinition>
     */
    public function getOptionDefinitions(): array
    {
        return [];
    }

    /**
     * Normalizes raw user/form/CLI options against this provider's declared option schema.
     *
     * @param array<string, mixed> $rawOptions
     *
     * @return array<string, mixed>
     */
    public function normalizeOptions(array $rawOptions): array
    {
        $normalized = [];

        foreach ($this->getOptionDefinitions() as $def) {
            $hasValue = \array_key_exists($def->name, $rawOptions);
            $raw = $hasValue ? $rawOptions[$def->name] : $def->default;

            $normalized[$def->name] = match ($def->type) {
                ProviderOptionDefinition::TYPE_CHECKBOX => $this->castBool($raw, (bool) $def->default),
                ProviderOptionDefinition::TYPE_NUMBER => $this->castOptionalNumber($raw, $def->default, $def->min, $def->max),
                ProviderOptionDefinition::TYPE_SELECT => $this->castSelect($raw, $def->choices, (string) ($def->default ?? '')),
                default => is_scalar($raw) ? trim((string) $raw) : (string) ($def->default ?? ''),
            };
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Accept-Language' => 'fr-FR,fr;q=0.9',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];
    }

    /**
     * @return list<FetchedListingDto>
     */
    protected function fallbackOrEmpty(string $query, ?float $maxPrice, ?int $limit, string $reason): array
    {
        $this->logger->info('Provider {provider} falling back for query "{query}": {reason}', [
            'provider' => $this->getName(),
            'query' => $query,
            'reason' => $reason,
            'fallback_to_mock' => $this->fallbackToMock,
        ]);

        if ($this->fallbackToMock) {
            return $this->mockListingProvider->generateRealisticFixtures($this->getName(), $query, $maxPrice, $limit ?? 20);
        }

        return [];
    }

    protected function resolveMaxPages(?int $limit, int $itemsPerPage, ?int $providerMaxPages = null): int
    {
        $safeUnlimited = max(1, $this->maxUnlimitedPages);

        if (null === $limit || $limit <= 0) {
            return null !== $providerMaxPages && $providerMaxPages > 0
                ? min($providerMaxPages, $safeUnlimited)
                : $safeUnlimited;
        }

        $neededPages = max(1, (int) ceil($limit / max(1, $itemsPerPage)));

        if (null !== $providerMaxPages && $providerMaxPages > 0) {
            return min($neededPages, $providerMaxPages, $safeUnlimited);
        }

        return min($neededPages, $safeUnlimited);
    }

    private function castBool(mixed $value, bool $default): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (null === $value) {
            return $default;
        }

        if (\is_int($value)) {
            return 0 !== $value;
        }

        if (\is_string($value)) {
            $v = strtolower(trim($value));
            if (\in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (\in_array($v, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return $default;
    }

    private function castOptionalNumber(mixed $value, mixed $default, ?int $min, ?int $max): ?float
    {
        if (null === $value || '' === $value) {
            return is_numeric($default) ? (float) $default : null;
        }

        if (!is_numeric($value)) {
            return is_numeric($default) ? (float) $default : null;
        }

        $num = (float) $value;
        if (null !== $min && $num < $min) {
            $num = (float) $min;
        }
        if (null !== $max && $num > $max) {
            $num = (float) $max;
        }

        return $num;
    }

    /**
     * @param array<string, string> $choices
     */
    private function castSelect(mixed $value, array $choices, string $default): string
    {
        $str = is_scalar($value) ? trim((string) $value) : $default;

        if ([] !== $choices && !isset($choices[$str])) {
            return $default;
        }

        return $str;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\FetchedListingDto;
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
    ) {
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
    protected function fallbackOrEmpty(string $query, ?float $maxPrice, int $limit, string $reason): array
    {
        $this->logger->info('Provider {provider} falling back for query "{query}": {reason}', [
            'provider' => $this->getName(),
            'query' => $query,
            'reason' => $reason,
            'fallback_to_mock' => $this->fallbackToMock,
        ]);

        if ($this->fallbackToMock) {
            return $this->mockListingProvider->generateRealisticFixtures($this->getName(), $query, $maxPrice, $limit);
        }

        return [];
    }
}

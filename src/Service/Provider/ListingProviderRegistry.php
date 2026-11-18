<?php

declare(strict_types=1);

namespace App\Service\Provider;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class ListingProviderRegistry
{
    /** @var array<string, ListingProviderInterface> */
    private array $providersByName = [];

    /**
     * @param iterable<ListingProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.listing_provider')]
        iterable $providers,
        #[Autowire(env: 'LISTING_PROVIDERS_ENABLED')]
        private readonly string $defaultEnabledProviders = 'leboncoin,vinted,backmarket,mock',
    ) {
        foreach ($providers as $provider) {
            $this->providersByName[strtolower($provider->getName())] = $provider;
        }
    }

    public function get(string $name): ListingProviderInterface
    {
        $normalized = strtolower(trim($name));
        if (!isset($this->providersByName[$normalized])) {
            throw new \InvalidArgumentException(sprintf('Unknown listing provider "%s". Available providers: %s', $name, implode(', ', $this->getAvailableProviderNames())));
        }

        return $this->providersByName[$normalized];
    }

    /**
     * @param list<string>|null $names
     *
     * @return list<ListingProviderInterface>
     */
    public function resolveProviders(?array $names = null): array
    {
        if (null === $names || [] === $names) {
            $names = array_values(array_filter(array_map('trim', explode(',', $this->defaultEnabledProviders)), static fn (string $v): bool => '' !== $v));
        }

        $resolved = [];
        foreach ($names as $name) {
            $provider = $this->get($name);
            $resolved[strtolower($provider->getName())] = $provider;
        }

        return array_values($resolved);
    }

    /**
     * @return list<string>
     */
    public function getAvailableProviderNames(): array
    {
        return array_keys($this->providersByName);
    }
}

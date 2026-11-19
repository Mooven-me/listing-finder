<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\ProviderOptionDefinition;
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

    /**
     * Returns declared option schemas keyed by provider name.
     *
     * @return array<string, list<ProviderOptionDefinition>>
     */
    public function getOptionSchemas(): array
    {
        $schemas = [];

        foreach ($this->providersByName as $name => $provider) {
            $definitions = $provider->getOptionDefinitions();
            if ([] !== $definitions) {
                $schemas[$name] = $definitions;
            }
        }

        return $schemas;
    }

    /**
     * Normalizes a raw map of [providerName => [optionName => rawValue]] against declared schemas.
     *
     * @param array<string, mixed> $rawByProvider
     * @param list<string>         $enabledProviders
     *
     * @return array<string, array<string, mixed>>
     */
    public function normalizeProviderOptions(array $rawByProvider, array $enabledProviders): array
    {
        $normalized = [];

        foreach ($enabledProviders as $providerName) {
            $key = strtolower(trim($providerName));
            if (!isset($this->providersByName[$key])) {
                continue;
            }

            $provider = $this->providersByName[$key];
            if ([] === $provider->getOptionDefinitions()) {
                continue;
            }

            $rawForProvider = isset($rawByProvider[$key]) && \is_array($rawByProvider[$key])
                ? $rawByProvider[$key]
                : [];

            // HTML forms omit unchecked checkboxes; treat missing checkbox keys as false
            // when the provider's options panel was submitted (indicated by the presence of its array).
            if (\array_key_exists($key, $rawByProvider) && \is_array($rawByProvider[$key])) {
                foreach ($provider->getOptionDefinitions() as $def) {
                    if (ProviderOptionDefinition::TYPE_CHECKBOX === $def->type && !\array_key_exists($def->name, $rawForProvider)) {
                        $rawForProvider[$def->name] = false;
                    }
                }
            }

            if ($provider instanceof AbstractHttpListingProvider) {
                $normalized[$key] = $provider->normalizeOptions($rawForProvider);
            } else {
                $normalized[$key] = $rawForProvider;
            }
        }

        return $normalized;
    }
}

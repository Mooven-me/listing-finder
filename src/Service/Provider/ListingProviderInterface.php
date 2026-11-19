<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\FetchedListingDto;
use App\Dto\Provider\ProviderOptionDefinition;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.listing_provider')]
interface ListingProviderInterface
{
    public function getName(): string;

    /**
     * @param array<string, mixed> $options Provider-specific filter/search options
     *
     * @return list<FetchedListingDto>
     */
    public function search(string $query, ?float $maxPrice = null, ?int $limit = 25, array $options = []): array;

    /**
     * Self-describing schema of supported options for dynamic UI & CLI handling.
     *
     * @return list<ProviderOptionDefinition>
     */
    public function getOptionDefinitions(): array;
}

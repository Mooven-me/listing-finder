<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Dto\Provider\FetchedListingDto;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.listing_provider')]
interface ListingProviderInterface
{
    public function getName(): string;

    /**
     * @return list<FetchedListingDto>
     */
    public function search(string $query, ?float $maxPrice = null, int $limit = 5): array;
}

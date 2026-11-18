<?php

declare(strict_types=1);

namespace App\Dto\Provider;

final readonly class FetchedListingDto
{
    /**
     * @param list<string> $imageUrls
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $title,
        public float $price,
        public string $url,
        public ?string $description = null,
        public string $currency = 'EUR',
        public array $imageUrls = [],
        public ?string $conditionState = null,
        public ?\DateTimeImmutable $publishedAt = null,
    ) {
    }
}

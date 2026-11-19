<?php

declare(strict_types=1);

namespace App\Dto\Provider;

final readonly class ListingDetailsDto
{
    /**
     * @param array<string, string> $attributes
     * @param list<string>          $imageUrls
     */
    public function __construct(
        public ?string $description = null,
        public ?string $categoryName = null,
        public ?string $brand = null,
        public array $attributes = [],
        public ?string $conditionState = null,
        public array $imageUrls = [],
    ) {
    }

    /**
     * Formats seller description + structured marketplace metadata into a clean text block
     * for storage and AI evaluation.
     */
    public function formatEnrichedDescription(?string $existingDescription = null): string
    {
        $parts = [];

        $body = null !== $this->description ? trim($this->description) : '';
        if ('' !== $body) {
            $parts[] = $body;
        } elseif (null !== $existingDescription && '' !== trim($existingDescription)) {
            $parts[] = trim($existingDescription);
        }

        $meta = [];
        if (null !== $this->categoryName && '' !== trim($this->categoryName)) {
            $meta[] = sprintf('Category: %s', trim($this->categoryName));
        }
        if (null !== $this->brand && '' !== trim($this->brand)) {
            $meta[] = sprintf('Brand: %s', trim($this->brand));
        }
        foreach ($this->attributes as $label => $value) {
            $cleanLabel = trim($label);
            $cleanValue = trim($value);
            if ('' !== $cleanLabel && '' !== $cleanValue) {
                $meta[] = sprintf('%s: %s', $cleanLabel, $cleanValue);
            }
        }

        if ([] !== $meta) {
            $parts[] = 'Attributes: '.implode(' | ', $meta);
        }

        if ([] === $parts) {
            return '[Listing details checked — no seller description provided.]';
        }

        return implode("\n", $parts);
    }
}

<?php

declare(strict_types=1);

namespace App\Message;

final readonly class CheckSearchAvailabilityMessage
{
    public function __construct(
        public int $searchRequestId,
        public int $maxListings = 50,
    ) {
    }
}

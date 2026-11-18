<?php

declare(strict_types=1);

namespace App\Message;

final readonly class EvaluateListingMessage
{
    public function __construct(
        public int $searchRequestId,
        public int $listingId,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Message;

final readonly class FetchSubTopicListingsMessage
{
    public function __construct(
        public int $searchRequestId,
        public string $subTopic,
        public string $providerName,
        public int $limit = 5,
    ) {
    }
}

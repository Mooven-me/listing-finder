<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SearchRequest;
use App\Message\FetchSubTopicListingsMessage;
use Symfony\Component\Messenger\MessageBusInterface;

class SearchDispatcherService
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function dispatchSearchJobs(SearchRequest $searchRequest, int $limitPerSubTopic = 5): int
    {
        $searchRequestId = $searchRequest->getId();
        if (null === $searchRequestId) {
            throw new \InvalidArgumentException('SearchRequest must be persisted before dispatching jobs.');
        }

        $dispatchedCount = 0;
        $limit = max(1, $limitPerSubTopic);

        foreach ($searchRequest->getGeneratedSubTopics() as $subTopic) {
            foreach ($searchRequest->getEnabledProviders() as $providerName) {
                $this->messageBus->dispatch(new FetchSubTopicListingsMessage(
                    searchRequestId: $searchRequestId,
                    subTopic: $subTopic,
                    providerName: $providerName,
                    limit: $limit,
                ));
                ++$dispatchedCount;
            }
        }

        return $dispatchedCount;
    }
}

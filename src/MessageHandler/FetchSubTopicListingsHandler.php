<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Listing;
use App\Enum\SearchStatus;
use App\Message\EvaluateListingMessage;
use App\Message\FetchSubTopicListingsMessage;
use App\Repository\ListingEvaluationRepository;
use App\Repository\ListingRepository;
use App\Repository\SearchRequestRepository;
use App\Service\Provider\ListingProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class FetchSubTopicListingsHandler
{
    public function __construct(
        private readonly SearchRequestRepository $searchRequestRepository,
        private readonly ListingRepository $listingRepository,
        private readonly ListingEvaluationRepository $evaluationRepository,
        private readonly ListingProviderRegistry $providerRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(FetchSubTopicListingsMessage $message): void
    {
        $searchRequest = $this->searchRequestRepository->find($message->searchRequestId);
        if (null === $searchRequest) {
            $this->logger->warning('SearchRequest not found for FetchSubTopicListingsMessage.', [
                'searchRequestId' => $message->searchRequestId,
            ]);

            return;
        }

        if (SearchStatus::Pending === $searchRequest->getStatus() || SearchStatus::Expanding === $searchRequest->getStatus()) {
            $searchRequest->setStatus(SearchStatus::Processing);
            $this->entityManager->flush();
        }

        $provider = $this->providerRegistry->get($message->providerName);
        $fetchedDtos = $provider->search(
            $message->subTopic,
            $searchRequest->getTargetPrice(),
            $message->limit,
        );

        $newListingIdsToEvaluate = [];

        foreach ($fetchedDtos as $dto) {
            $lockKey = sprintf(
                'listing-upsert-%d-%s-%s',
                $message->searchRequestId,
                $dto->provider,
                sha1($dto->externalId),
            );
            $lock = $this->lockFactory->createLock($lockKey, 30.0);
            $lock->acquire(true);

            try {
                $listing = $this->listingRepository->findOneBySearchProviderAndExternalId(
                    $searchRequest,
                    $dto->provider,
                    $dto->externalId,
                );

                if (null !== $listing) {
                    if ($listing->isComputed() || null !== $this->evaluationRepository->findOneBySearchAndListing($searchRequest, $listing)) {
                        $this->logger->debug('Skipping already computed listing for search request.', [
                            'searchRequestId' => $message->searchRequestId,
                            'provider' => $dto->provider,
                            'externalId' => $dto->externalId,
                        ]);

                        continue;
                    }
                } else {
                    $listing = new Listing(
                        searchRequest: $searchRequest,
                        provider: $dto->provider,
                        externalId: $dto->externalId,
                        searchTopicUsed: $message->subTopic,
                        title: $dto->title,
                        price: $dto->price,
                        url: $dto->url,
                        description: $dto->description,
                        currency: $dto->currency,
                        imageUrls: $dto->imageUrls,
                        conditionState: $dto->conditionState,
                        publishedAt: $dto->publishedAt,
                    );
                    $this->entityManager->persist($listing);
                    $this->entityManager->flush();
                }

                if (null !== $listing->getId()) {
                    $newListingIdsToEvaluate[] = $listing->getId();
                }
            } finally {
                $lock->release();
            }
        }

        foreach (array_unique($newListingIdsToEvaluate) as $listingId) {
            $this->messageBus->dispatch(new EvaluateListingMessage(
                searchRequestId: $message->searchRequestId,
                listingId: $listingId,
            ));
        }
    }
}

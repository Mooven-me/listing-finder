<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ListingEvaluation;
use App\Enum\SearchStatus;
use App\Message\EvaluateListingMessage;
use App\Repository\ListingEvaluationRepository;
use App\Repository\ListingRepository;
use App\Repository\SearchRequestRepository;
use App\Service\Ai\ListingEvaluatorAgent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class EvaluateListingHandler
{
    public function __construct(
        private readonly SearchRequestRepository $searchRequestRepository,
        private readonly ListingRepository $listingRepository,
        private readonly ListingEvaluationRepository $evaluationRepository,
        private readonly ListingEvaluatorAgent $evaluatorAgent,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EvaluateListingMessage $message): void
    {
        $lockKey = sprintf('listing-evaluate-%d-%d', $message->searchRequestId, $message->listingId);
        $lock = $this->lockFactory->createLock($lockKey, 120.0);

        if (!$lock->acquire(true)) {
            return;
        }

        try {
            $searchRequest = $this->searchRequestRepository->find($message->searchRequestId);
            $listing = $this->listingRepository->find($message->listingId);

            if (null === $searchRequest || null === $listing) {
                $this->logger->warning('SearchRequest or Listing missing during evaluation.', [
                    'searchRequestId' => $message->searchRequestId,
                    'listingId' => $message->listingId,
                ]);

                return;
            }

            $existing = $this->evaluationRepository->findOneBySearchAndListing($searchRequest, $listing);
            if (null !== $existing) {
                return;
            }

            $resultDto = $this->evaluatorAgent->evaluate($searchRequest, $listing);

            $evaluation = new ListingEvaluation(
                searchRequest: $searchRequest,
                listing: $listing,
                scoreOutOf100: $resultDto->score,
                priceScore: $resultDto->priceScore,
                workingConditionScore: $resultDto->workingConditionScore,
                productAgeScore: $resultDto->productAgeScore,
                relevanceScore: $resultDto->relevanceScore,
                aiReasoning: $resultDto->reasoning,
                imageAnalyzed: $resultDto->imageAnalyzed,
                isWorking: $resultDto->isWorking,
                estimatedAge: $resultDto->estimatedAge,
            );

            $this->entityManager->persist($evaluation);
            $searchRequest->setStatus(SearchStatus::Completed);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }
}

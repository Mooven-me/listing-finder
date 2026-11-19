<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Listing;
use App\Entity\ListingEvaluation;
use App\Enum\SearchStatus;
use App\Message\EvaluateListingMessage;
use App\Repository\ListingEvaluationRepository;
use App\Repository\ListingRepository;
use App\Repository\SearchRequestRepository;
use App\Service\Ai\ListingEvaluatorAgent;
use App\Service\Provider\AbstractHttpListingProvider;
use App\Service\Provider\ListingProviderRegistry;
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
        private readonly ListingProviderRegistry $providerRegistry,
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

            if ($listing->isComputed() || null !== $this->evaluationRepository->findOneBySearchAndListing($searchRequest, $listing)) {
                if (!$listing->isComputed()) {
                    $listing->setIsComputed(true);
                    $this->entityManager->flush();
                }

                return;
            }

            $this->enrichListingDetailsIfMissing($listing);

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

            $listing->setIsComputed(true);
            $this->entityManager->persist($evaluation);
            $this->entityManager->flush();

            $uncomputedCount = $this->listingRepository->count([
                'searchRequest' => $searchRequest,
                'isComputed' => false,
            ]);

            if (0 === $uncomputedCount) {
                $searchRequest->setStatus(SearchStatus::Completed);
                $this->entityManager->flush();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Fetches the full individual listing page once before AI evaluation when the search-results
     * crawl did not include the seller's real description (e.g. LeBonCoin searchData omits body,
     * Vinted catalog cards only carry title/brand/condition).
     */
    private function enrichListingDetailsIfMissing(Listing $listing): void
    {
        $currentDesc = trim((string) ($listing->getDescription() ?? ''));
        $providerName = strtolower($listing->getProvider());

        $needsDetailFetch = match ($providerName) {
            'leboncoin' => '' === $currentDesc,
            'vinted' => '' === $currentDesc || (!str_contains($currentDesc, "\n") && !str_contains($currentDesc, '[Listing details checked')),
            default => false,
        };

        if (!$needsDetailFetch) {
            return;
        }

        try {
            $provider = $this->providerRegistry->get($providerName);
        } catch (\InvalidArgumentException) {
            return;
        }

        if (!$provider instanceof AbstractHttpListingProvider) {
            return;
        }

        $details = $provider->fetchListingDetails($listing->getUrl());
        if (null === $details) {
            return;
        }

        $enrichedDescription = $details->formatEnrichedDescription($listing->getDescription());
        if ('vinted' === $providerName && !str_contains($enrichedDescription, "\n") && !str_contains($enrichedDescription, '[Listing details checked')) {
            $enrichedDescription .= "\n[Listing details checked]";
        }

        $listing->setDescription($enrichedDescription);

        if (null === $listing->getConditionState() && null !== $details->conditionState) {
            $listing->setConditionState($details->conditionState);
        }
        if ([] === $listing->getImageUrls() && [] !== $details->imageUrls) {
            $listing->setImageUrls($details->imageUrls);
        }

        $this->entityManager->flush();
    }
}

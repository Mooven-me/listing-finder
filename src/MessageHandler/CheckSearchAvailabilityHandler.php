<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckSearchAvailabilityMessage;
use App\Repository\ListingEvaluationRepository;
use App\Repository\SearchRequestRepository;
use App\Service\Provider\AbstractHttpListingProvider;
use App\Service\Provider\ListingProviderRegistry;
use App\Service\Provider\ProviderHealthService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Safely re-checks live availability ONLY for unsold recommended deals (score >= 50),
 * bounded per run with polite provider throttling and an automatic circuit breaker on 403/429.
 */
#[AsMessageHandler]
class CheckSearchAvailabilityHandler
{
    public function __construct(
        private readonly SearchRequestRepository $searchRequestRepository,
        private readonly ListingEvaluationRepository $evaluationRepository,
        private readonly ListingProviderRegistry $providerRegistry,
        private readonly ProviderHealthService $providerHealth,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckSearchAvailabilityMessage $message): void
    {
        $lock = $this->lockFactory->createLock(sprintf('availability-check-%d', $message->searchRequestId), 300.0);
        if (!$lock->acquire(false)) {
            return;
        }

        try {
            $searchRequest = $this->searchRequestRepository->find($message->searchRequestId);
            if (null === $searchRequest) {
                return;
            }

            $limit = max(1, min(100, $message->maxListings));
            $evaluations = $this->evaluationRepository->findTopUnsoldForAvailabilityCheck($searchRequest, 50, $limit);

            $checked = 0;
            $markedSold = 0;
            /** @var array<string, int> $rateLimitHits */
            $rateLimitHits = [];

            foreach ($evaluations as $eval) {
                $listing = $eval->getListing();
                $providerName = strtolower($listing->getProvider());

                if (($rateLimitHits[$providerName] ?? 0) >= 2 || $this->providerHealth->isCoolingDown($providerName)) {
                    continue;
                }

                try {
                    $provider = $this->providerRegistry->get($providerName);
                } catch (\InvalidArgumentException) {
                    continue;
                }

                if (!$provider instanceof AbstractHttpListingProvider) {
                    continue;
                }

                $details = $provider->fetchListingDetails($listing->getUrl());
                ++$checked;

                if (null === $details) {
                    continue;
                }

                if ($details->rateLimited) {
                    $rateLimitHits[$providerName] = ($rateLimitHits[$providerName] ?? 0) + 1;
                    continue;
                }

                if ($details->isSoldOrUnavailable) {
                    $listing->setIsSold(true);
                    ++$markedSold;
                }
            }

            if ($markedSold > 0) {
                $this->entityManager->flush();
            }

            $this->logger->info('Availability check completed for search #{searchId}: checked {checked} top listings, marked {sold} as sold.', [
                'searchId' => $message->searchRequestId,
                'checked' => $checked,
                'sold' => $markedSold,
            ]);
        } finally {
            $lock->release();
        }
    }
}

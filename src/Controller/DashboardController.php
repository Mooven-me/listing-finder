<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Dashboard\DashboardFilterDto;
use App\Dto\Dashboard\NewSearchFormDto;
use App\Entity\CronTask;
use App\Entity\ListingEvaluation;
use App\Entity\SearchRequest;
use App\Enum\DealCategory;
use App\Enum\SearchStatus;
use App\Message\CheckSearchAvailabilityMessage;
use App\Message\EvaluateListingMessage;
use App\Repository\ListingEvaluationRepository;
use App\Repository\ListingRepository;
use App\Repository\SearchRequestRepository;
use App\Service\Ai\LlmClientInterface;
use App\Service\Ai\TopicExpansionAgent;
use App\Service\Dashboard\DashboardAnalyticsService;
use App\Service\Provider\ListingProviderRegistry;
use App\Service\Provider\ProviderHealthService;
use App\Service\SearchDispatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly SearchRequestRepository $searchRequestRepository,
        private readonly ListingRepository $listingRepository,
        private readonly ListingEvaluationRepository $evaluationRepository,
        private readonly DashboardAnalyticsService $analyticsService,
        private readonly TopicExpansionAgent $topicExpansionAgent,
        private readonly LlmClientInterface $llmClient,
        private readonly ListingProviderRegistry $providerRegistry,
        private readonly ProviderHealthService $providerHealth,
        private readonly SearchDispatcherService $searchDispatcher,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(
        #[MapQueryString] DashboardFilterDto $filter = new DashboardFilterDto(),
    ): Response {
        $globalStats = $this->analyticsService->getGlobalStats();
        $searchesSummaries = $this->analyticsService->getSearchesSummaries();

        $activeSearch = null;
        if (null !== $filter->search) {
            $activeSearch = $this->searchRequestRepository->find($filter->search);
        }
        if (null === $activeSearch && [] !== $searchesSummaries) {
            /** @var SearchRequest $firstSearch */
            $firstSearch = $searchesSummaries[0]['entity'];
            $activeSearch = $firstSearch;
        }

        $analytics = null;
        $pagination = [
            'items' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => $filter->normalizedPerPage(),
            'total_pages' => 1,
        ];
        $groupedEvaluations = [
            'must_buy' => [],
            'extremely_good' => [],
            'good' => [],
            'bad' => [],
            'poor' => [],
        ];

        if (null !== $activeSearch) {
            $allEvaluations = $this->evaluationRepository->findForSearchRequest($activeSearch);
            $analytics = $this->analyticsService->buildSearchAnalytics($activeSearch, $allEvaluations);

            if ($this->shouldMarkSearchCompleted($activeSearch, $analytics, (int) $globalStats['queued_messages'])) {
                $activeSearch->setStatus(SearchStatus::Completed);
                $this->entityManager->flush();
            }

            $pagination = $this->evaluationRepository->findFilteredPaginatedForDashboard($activeSearch, $filter);

            foreach ($pagination['items'] as $eval) {
                $groupedEvaluations[$eval->getCategory()->value][] = $eval;
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'global_stats' => $globalStats,
            'searches_summaries' => $searchesSummaries,
            'active_search' => $activeSearch,
            'analytics' => $analytics,
            'filter' => $filter,
            'pagination' => $pagination,
            'filtered_evaluations' => $pagination['items'],
            'grouped_evaluations' => $groupedEvaluations,
            'available_providers' => $this->providerRegistry->getAvailableProviderNames(),
            'provider_option_schemas' => $this->providerRegistry->getOptionSchemas(),
            'provider_health' => null !== $activeSearch ? $this->providerHealth->getActiveBlocks($activeSearch->getEnabledProviders()) : [],
            'vision_supported' => $this->llmClient->supportsVision(),
            'vision_model' => $this->llmClient->getVisionModelName(),
        ]);
    }

    #[Route('/api/dashboard/status/{id}', name: 'app_dashboard_status', methods: ['GET'])]
    public function status(int $id): JsonResponse
    {
        $searchRequest = $this->searchRequestRepository->find($id);
        if (null === $searchRequest) {
            return $this->json(['error' => 'Search request not found'], Response::HTTP_NOT_FOUND);
        }

        $globalStats = $this->analyticsService->getGlobalStats();
        $allEvaluations = $this->evaluationRepository->findForSearchRequest($searchRequest);
        $analytics = $this->analyticsService->buildSearchAnalytics($searchRequest, $allEvaluations);

        if ($this->shouldMarkSearchCompleted($searchRequest, $analytics, (int) $globalStats['queued_messages'])) {
            $searchRequest->setStatus(SearchStatus::Completed);
            $this->entityManager->flush();
        }

        return $this->json([
            'search_id' => $searchRequest->getId(),
            'status' => $searchRequest->getStatus()->value,
            'global' => $globalStats,
            'total_ads' => $analytics['total_stored_ads'],
            'sold_count' => $analytics['sold_count'],
            'evaluated_ads' => $analytics['total_evaluated_ads'],
            'progress_percent' => $analytics['progress_percent'],
            'must_buy_count' => $analytics['must_buy_count'],
            'extremely_good_count' => $analytics['extremely_good_count'],
            'good_count' => $analytics['good_count'],
            'bad_count' => $analytics['bad_count'],
            'poor_count' => $analytics['poor_count'],
            'unseen_total' => $analytics['unseen_total'],
            'unseen_recommended_count' => $analytics['unseen_recommended_count'],
            'unseen_must_buy' => $analytics['unseen_must_buy'],
            'unseen_extremely_good' => $analytics['unseen_extremely_good'],
            'unseen_good' => $analytics['unseen_good'],
            'last_viewed_at' => $analytics['last_viewed_at'],
            'discovery_timeline' => $analytics['discovery_timeline'],
            'provider_health' => $this->providerHealth->getActiveBlocks($searchRequest->getEnabledProviders()),
            'avg_score' => $analytics['avg_score'],
            'avg_price' => $analytics['avg_price'],
            'min_price' => $analytics['min_price'],
            'working_count' => $analytics['working_count'],
            'sub_score_averages' => $analytics['sub_score_averages'],
            'best_deal' => $analytics['best_deal_summary'],
            'provider_stats' => $analytics['provider_stats'],
            'sub_topic_stats' => $analytics['sub_topic_stats'],
            'scatter_points' => $analytics['scatter_points'],
            'cron' => $analytics['cron'],
            'queued_messages' => $globalStats['queued_messages'],
        ]);
    }

    #[Route('/search/new', name: 'app_dashboard_search_new', methods: ['POST'])]
    public function createSearch(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('dashboard_new_search', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token. Please try again.');

            return $this->redirectToRoute('app_dashboard');
        }

        $rawProviders = $request->request->all('providers');
        $providers = array_values(array_filter(array_map(
            static fn (mixed $v): string => is_scalar($v) ? strtolower(trim((string) $v)) : '',
            $rawProviders,
        )));

        if ([] === $providers) {
            $providers = ['leboncoin', 'vinted', 'backmarket'];
        }

        $rawSpecification = trim((string) $request->request->get('topic', ''));
        $legacyRequirements = trim((string) $request->request->get('user_requirements', ''));
        if ('' !== $legacyRequirements) {
            $rawSpecification = trim($rawSpecification."\n".$legacyRequirements);
        }

        $rawSubTopicsCount = trim((string) $request->request->get('sub_topics_count', ''));
        $subTopicsCount = '' !== $rawSubTopicsCount && is_numeric($rawSubTopicsCount)
            ? max(1, min(15, (int) $rawSubTopicsCount))
            : null;

        $rawLimit = trim((string) $request->request->get('limit', ''));
        $limitPerSubTopic = ('' !== $rawLimit && is_numeric($rawLimit) && (int) $rawLimit > 0)
            ? max(1, min(5000, (int) $rawLimit))
            : null;

        /** @var array<string, mixed> $rawProviderOptions */
        $rawProviderOptions = $request->request->all('provider_options');

        $dto = new NewSearchFormDto(
            topic: $rawSpecification,
            targetPrice: (float) $request->request->get('target_price', 50),
            subTopicsCount: $subTopicsCount,
            enableImages: $this->llmClient->supportsVision() && (bool) $request->request->get('enable_images', false),
            limitPerSubTopic: $limitPerSubTopic,
            intervalMinutes: max(5, min(1440, (int) $request->request->get('interval', 30))),
            providers: $providers,
            providerOptions: [],
        );

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $this->addFlash('error', (string) $violations->get(0)->getMessage());

            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $resolvedProviders = $this->providerRegistry->resolveProviders($dto->providers);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_dashboard');
        }

        $providerNames = array_map(static fn ($p): string => $p->getName(), $resolvedProviders);
        $normalizedProviderOptions = $this->providerRegistry->normalizeProviderOptions($rawProviderOptions, $providerNames);

        // Keep SearchRequest::topic within 255 chars and store full multi-line details in userRequirements if longer
        $normalizedOneLine = trim((string) preg_replace('/\s+/', ' ', $dto->topic));
        $shortTopic = mb_substr($normalizedOneLine, 0, 250);
        $extraDetails = mb_strlen($dto->topic) > 250 || str_contains($dto->topic, "\n") ? $dto->topic : null;

        $searchRequest = new SearchRequest(
            topic: $shortTopic,
            targetPrice: $dto->targetPrice,
            enableImages: $dto->enableImages,
            userRequirements: $extraDetails,
            enabledProviders: $providerNames,
            providerOptions: $normalizedProviderOptions,
        );
        $searchRequest->setStatus(SearchStatus::Expanding);
        $this->entityManager->persist($searchRequest);
        $this->entityManager->flush();

        $expansion = $this->topicExpansionAgent->expandTopic($dto->topic, null, $dto->subTopicsCount);
        $searchRequest->setGeneratedSubTopics($expansion->subTopics);
        $searchRequest->setStatus(SearchStatus::Processing);

        $cronTask = new CronTask(
            searchRequest: $searchRequest,
            intervalMinutes: $dto->intervalMinutes,
            limitPerSubTopic: $dto->limitPerSubTopic,
        );
        $searchRequest->setCronTask($cronTask);
        $this->entityManager->persist($cronTask);
        $this->entityManager->flush();

        $dispatched = $this->searchDispatcher->dispatchSearchJobs($searchRequest, $dto->limitPerSubTopic);

        $this->addFlash(
            'success',
            sprintf(
                'Search #%d "%s" created with %d AI sub-topics (%d provider jobs dispatched).',
                (int) $searchRequest->getId(),
                $dto->topic,
                \count($expansion->subTopics),
                $dispatched,
            ),
        );

        return $this->redirectToRoute('app_dashboard', ['search' => $searchRequest->getId()]);
    }

    #[Route('/search/{id}/refresh', name: 'app_dashboard_search_refresh', methods: ['POST'])]
    public function refreshSearch(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('dashboard_action_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_dashboard', ['search' => $id]);
        }

        $searchRequest = $this->searchRequestRepository->find($id);
        if (null === $searchRequest) {
            throw $this->createNotFoundException('Search request not found.');
        }

        $limit = $searchRequest->getCronTask() ? $searchRequest->getCronTask()->getLimitPerSubTopic() : 25;
        $searchRequest->setStatus(SearchStatus::Processing);
        $searchRequest->getCronTask()?->markExecuted();
        $this->entityManager->flush();

        $dispatched = $this->searchDispatcher->dispatchSearchJobs($searchRequest, $limit);
        $this->addFlash('success', sprintf('Dispatched %d incremental provider fetch jobs for "%s".', $dispatched, $searchRequest->getTopic()));

        return $this->redirectToRoute('app_dashboard', ['search' => $id]);
    }

    #[Route('/search/{id}/reevaluate', name: 'app_dashboard_search_reevaluate', methods: ['POST'])]
    public function reevaluateSearch(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('dashboard_action_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_dashboard', ['search' => $id]);
        }

        $searchRequest = $this->searchRequestRepository->find($id);
        if (null === $searchRequest) {
            throw $this->createNotFoundException('Search request not found.');
        }

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM listing_evaluation WHERE search_request_id = :id', ['id' => $id]);
        $connection->executeStatement('UPDATE listing SET is_computed = false WHERE search_request_id = :id', ['id' => $id]);

        $searchRequest->setStatus(SearchStatus::Processing);
        $this->entityManager->flush();

        $listings = $this->listingRepository->findBy(['searchRequest' => $searchRequest]);
        foreach ($listings as $listing) {
            if (null !== $listing->getId()) {
                $this->messageBus->dispatch(new EvaluateListingMessage(
                    searchRequestId: $id,
                    listingId: $listing->getId(),
                ));
            }
        }

        $this->addFlash('success', sprintf('Queued %d listings for strict AI re-evaluation.', \count($listings)));

        return $this->redirectToRoute('app_dashboard', ['search' => $id]);
    }

    #[Route('/search/{id}/mark-seen', name: 'app_dashboard_search_mark_seen', methods: ['POST'])]
    public function markSeen(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('dashboard_action_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_dashboard', ['search' => $id]);
        }

        $searchRequest = $this->searchRequestRepository->find($id);
        if (null === $searchRequest) {
            throw $this->createNotFoundException('Search request not found.');
        }

        $searchRequest->setLastViewedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->addFlash('info', sprintf('Marked all current listings as seen for Search #%d. Future cron discoveries will be highlighted as NEW.', $id));

        return $this->redirectToRoute('app_dashboard', ['search' => $id]);
    }

    #[Route('/search/{id}/check-availability', name: 'app_dashboard_search_check_availability', methods: ['POST'])]
    public function checkAvailability(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('dashboard_action_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_dashboard', ['search' => $id]);
        }

        $searchRequest = $this->searchRequestRepository->find($id);
        if (null === $searchRequest) {
            throw $this->createNotFoundException('Search request not found.');
        }

        $this->messageBus->dispatch(new CheckSearchAvailabilityMessage(
            searchRequestId: $id,
            maxListings: 50,
        ));

        $this->addFlash('success', sprintf('Queued safe availability check for top unsold deals (score >= 50, up to 50 ads) on Search #%d.', $id));

        return $this->redirectToRoute('app_dashboard', ['search' => $id]);
    }

    #[Route('/search/{id}/cron-toggle', name: 'app_dashboard_search_cron_toggle', methods: ['POST'])]
    public function toggleCron(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('dashboard_action_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_dashboard', ['search' => $id]);
        }

        $searchRequest = $this->searchRequestRepository->find($id);
        if (null === $searchRequest) {
            throw $this->createNotFoundException('Search request not found.');
        }

        $existingCron = $searchRequest->getCronTask();
        if (null !== $existingCron) {
            $searchRequest->setCronTask(null);
            $this->entityManager->remove($existingCron);
            $this->entityManager->flush();
            $this->addFlash('info', sprintf('Paused recurring cron task for Search #%d.', $id));
        } else {
            $cronTask = new CronTask($searchRequest, 30, null);
            $searchRequest->setCronTask($cronTask);
            $this->entityManager->persist($cronTask);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Enabled recurring 30-minute cron task for Search #%d.', $id));
        }

        return $this->redirectToRoute('app_dashboard', ['search' => $id]);
    }

    #[Route('/search/{id}/delete', name: 'app_dashboard_search_delete', methods: ['POST'])]
    public function deleteSearch(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('dashboard_action_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_dashboard');
        }

        $searchRequest = $this->searchRequestRepository->find($id);
        if (null !== $searchRequest) {
            $topic = $searchRequest->getTopic();
            $this->entityManager->remove($searchRequest);
            $this->entityManager->flush();
            $this->addFlash('info', sprintf('Deleted search #%d ("%s") and all its listings.', $id, $topic));
        }

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * @param array<string, mixed> $analytics
     */
    private function shouldMarkSearchCompleted(SearchRequest $searchRequest, array $analytics, int $queuedMessages): bool
    {
        if (SearchStatus::Completed === $searchRequest->getStatus()) {
            return false;
        }

        $totalStored = (int) ($analytics['total_stored_ads'] ?? 0);
        $totalEvaluated = (int) ($analytics['total_evaluated_ads'] ?? 0);
        if ($totalEvaluated < $totalStored) {
            return false;
        }

        if ($totalStored > 0) {
            return true;
        }

        return SearchStatus::Processing === $searchRequest->getStatus() && 0 === $queuedMessages;
    }
}

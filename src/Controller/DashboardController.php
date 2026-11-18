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
use App\Repository\ListingEvaluationRepository;
use App\Repository\ListingRepository;
use App\Repository\SearchRequestRepository;
use App\Service\Ai\TopicExpansionAgent;
use App\Service\Dashboard\DashboardAnalyticsService;
use App\Service\Provider\ListingProviderRegistry;
use App\Service\SearchDispatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
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
        private readonly ListingProviderRegistry $providerRegistry,
        private readonly SearchDispatcherService $searchDispatcher,
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
        $filteredEvaluations = [];
        $groupedEvaluations = [
            'must_buy' => [],
            'good' => [],
            'pass' => [],
        ];

        if (null !== $activeSearch) {
            $allEvaluations = $this->evaluationRepository->findForSearchRequest($activeSearch);
            $analytics = $this->analyticsService->buildSearchAnalytics($activeSearch, $allEvaluations);
            $filteredEvaluations = $this->evaluationRepository->findFilteredForDashboard($activeSearch, $filter);

            foreach ($filteredEvaluations as $eval) {
                match ($eval->getCategory()) {
                    DealCategory::MustBuy => $groupedEvaluations['must_buy'][] = $eval,
                    DealCategory::Good => $groupedEvaluations['good'][] = $eval,
                    DealCategory::Pass => $groupedEvaluations['pass'][] = $eval,
                };
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'global_stats' => $globalStats,
            'searches_summaries' => $searchesSummaries,
            'active_search' => $activeSearch,
            'analytics' => $analytics,
            'filter' => $filter,
            'filtered_evaluations' => $filteredEvaluations,
            'grouped_evaluations' => $groupedEvaluations,
            'available_providers' => $this->providerRegistry->getAvailableProviderNames(),
        ]);
    }

    #[Route('/api/dashboard/status/{id}', name: 'app_dashboard_status', methods: ['GET'])]
    public function status(int $id): JsonResponse
    {
        $searchRequest = $this->searchRequestRepository->find($id);
        if (null === $searchRequest) {
            return $this->json(['error' => 'Search request not found'], Response::HTTP_NOT_FOUND);
        }

        $totalAds = $this->listingRepository->count(['searchRequest' => $searchRequest]);
        $evaluatedAds = $this->evaluationRepository->count(['searchRequest' => $searchRequest]);
        $mustBuyCount = $this->evaluationRepository->count([
            'searchRequest' => $searchRequest,
            'category' => DealCategory::MustBuy,
        ]);
        $goodCount = $this->evaluationRepository->count([
            'searchRequest' => $searchRequest,
            'category' => DealCategory::Good,
        ]);
        $passCount = $this->evaluationRepository->count([
            'searchRequest' => $searchRequest,
            'category' => DealCategory::Pass,
        ]);

        $queuedMessages = 0;
        try {
            $queuedMessages = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages');
        } catch (\Throwable) {
            $queuedMessages = 0;
        }

        $progressPercent = $totalAds > 0 ? min(100, (int) round(($evaluatedAds / $totalAds) * 100)) : 0;

        if ($totalAds > 0 && $evaluatedAds >= $totalAds && SearchStatus::Completed !== $searchRequest->getStatus()) {
            $searchRequest->setStatus(SearchStatus::Completed);
            $this->entityManager->flush();
        }

        return $this->json([
            'search_id' => $searchRequest->getId(),
            'status' => $searchRequest->getStatus()->value,
            'total_ads' => $totalAds,
            'evaluated_ads' => $evaluatedAds,
            'must_buy_count' => $mustBuyCount,
            'good_count' => $goodCount,
            'pass_count' => $passCount,
            'queued_messages' => $queuedMessages,
            'progress_percent' => $progressPercent,
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

        $dto = new NewSearchFormDto(
            topic: trim((string) $request->request->get('topic', '')),
            targetPrice: (float) $request->request->get('target_price', 50),
            userRequirements: '' !== trim((string) $request->request->get('user_requirements', ''))
                ? trim((string) $request->request->get('user_requirements', ''))
                : null,
            enableImages: (bool) $request->request->get('enable_images', false),
            limitPerSubTopic: max(1, min(100, (int) $request->request->get('limit', 25))),
            intervalMinutes: max(5, min(1440, (int) $request->request->get('interval', 30))),
            providers: $providers,
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

        $searchRequest = new SearchRequest(
            topic: $dto->topic,
            targetPrice: $dto->targetPrice,
            enableImages: $dto->enableImages,
            userRequirements: $dto->userRequirements,
            enabledProviders: $providerNames,
        );
        $searchRequest->setStatus(SearchStatus::Expanding);
        $this->entityManager->persist($searchRequest);
        $this->entityManager->flush();

        $expansion = $this->topicExpansionAgent->expandTopic($dto->topic, $dto->userRequirements);
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

        $limit = $searchRequest->getCronTask()?->getLimitPerSubTopic() ?? 25;
        $searchRequest->setStatus(SearchStatus::Processing);
        $searchRequest->getCronTask()?->markExecuted();
        $this->entityManager->flush();

        $dispatched = $this->searchDispatcher->dispatchSearchJobs($searchRequest, $limit);
        $this->addFlash('success', sprintf('Dispatched %d incremental provider fetch jobs for "%s".', $dispatched, $searchRequest->getTopic()));

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
            $this->addFlash('info', sprintf('Stopped recurring 30-minute cron task for Search #%d.', $id));
        } else {
            $cronTask = new CronTask($searchRequest, 30, 25);
            $searchRequest->setCronTask($cronTask);
            $this->entityManager->persist($cronTask);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Re-enabled recurring 30-minute cron task for Search #%d.', $id));
        }

        return $this->redirectToRoute('app_dashboard', ['search' => $id]);
    }

    #[Route('/search/{id}/delete', name: 'app_dashboard_search_delete', methods: ['POST'])]
    public function deleteSearch(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('dashboard_action_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_dashboard', ['search' => $id]);
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
}

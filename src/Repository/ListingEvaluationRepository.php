<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\Dashboard\DashboardFilterDto;
use App\Entity\Listing;
use App\Entity\ListingEvaluation;
use App\Entity\SearchRequest;
use App\Enum\DealCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ListingEvaluation>
 */
class ListingEvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListingEvaluation::class);
    }

    public function findOneBySearchAndListing(SearchRequest $searchRequest, Listing $listing): ?ListingEvaluation
    {
        return $this->findOneBy([
            'searchRequest' => $searchRequest,
            'listing' => $listing,
        ]);
    }

    /**
     * @param list<DealCategory>|null $categories
     *
     * @return list<ListingEvaluation>
     */
    public function findForSearchRequest(SearchRequest $searchRequest, ?array $categories = null): array
    {
        $qb = $this->createQueryBuilder('le')
            ->innerJoin('le.listing', 'l')
            ->addSelect('l')
            ->andWhere('le.searchRequest = :searchRequest')
            ->setParameter('searchRequest', $searchRequest)
            ->orderBy('le.scoreOutOf100', 'DESC')
            ->addOrderBy('l.price', 'ASC');

        if (null !== $categories && [] !== $categories) {
            $qb->andWhere('le.category IN (:categories)')
                ->setParameter('categories', array_map(static fn (DealCategory $c): string => $c->value, $categories));
        }

        /** @var list<ListingEvaluation> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }

    /**
     * @return array{items: list<ListingEvaluation>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function findFilteredPaginatedForDashboard(SearchRequest $searchRequest, DashboardFilterDto $filter): array
    {
        $qb = $this->createFilteredQueryBuilder($searchRequest, $filter);

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(le.id)')->getQuery()->getSingleScalarResult();

        $perPage = $filter->normalizedPerPage();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($filter->normalizedPage(), $totalPages);
        $offset = ($page - 1) * $perPage;

        $qb->addSelect('l')
            ->orderBy('le.scoreOutOf100', 'DESC')
            ->addOrderBy('l.price', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage);

        /** @var list<ListingEvaluation> $items */
        $items = $qb->getQuery()->getResult();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return list<ListingEvaluation>
     */
    public function findFilteredForDashboard(SearchRequest $searchRequest, DashboardFilterDto $filter): array
    {
        $qb = $this->createFilteredQueryBuilder($searchRequest, $filter)
            ->addSelect('l')
            ->orderBy('le.scoreOutOf100', 'DESC')
            ->addOrderBy('l.price', 'ASC');

        /** @var list<ListingEvaluation> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }

    private function createFilteredQueryBuilder(SearchRequest $searchRequest, DashboardFilterDto $filter): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('le')
            ->innerJoin('le.listing', 'l')
            ->andWhere('le.searchRequest = :searchRequest')
            ->setParameter('searchRequest', $searchRequest);

        $selectedCategory = $filter->resolvedCategory();
        if (null !== $selectedCategory) {
            $qb->andWhere('le.category = :category')
                ->setParameter('category', $selectedCategory->value);
        } elseif (!$filter->showPoor) {
            $qb->andWhere('le.category IN (:defaultCategories)')
                ->setParameter('defaultCategories', [
                    DealCategory::MustBuy->value,
                    DealCategory::ExtremelyGood->value,
                    DealCategory::Good->value,
                    DealCategory::Bad->value,
                ]);
        }

        $provider = $filter->normalizedProvider();
        if (null !== $provider) {
            $qb->andWhere('LOWER(l.provider) = :provider')
                ->setParameter('provider', $provider);
        }

        $subTopic = $filter->normalizedSubTopic();
        if (null !== $subTopic) {
            $qb->andWhere('l.searchTopicUsed = :subTopic')
                ->setParameter('subTopic', $subTopic);
        }

        $query = $filter->normalizedQuery();
        if (null !== $query) {
            $qb->andWhere('LOWER(l.title) LIKE LOWER(:q) OR LOWER(le.aiReasoning) LIKE LOWER(:q) OR LOWER(COALESCE(l.description, \'\')) LIKE LOWER(:q)')
                ->setParameter('q', '%'.$query.'%');
        }

        if (null !== $filter->maxPrice && $filter->maxPrice > 0) {
            $qb->andWhere('l.price <= :maxPrice')
                ->setParameter('maxPrice', $filter->maxPrice);
        }

        if ($filter->onlyWorking) {
            $qb->andWhere('le.isWorking = true');
        }

        return $qb;
    }
}

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
     * @return list<ListingEvaluation>
     */
    public function findFilteredForDashboard(SearchRequest $searchRequest, DashboardFilterDto $filter): array
    {
        $qb = $this->createQueryBuilder('le')
            ->innerJoin('le.listing', 'l')
            ->addSelect('l')
            ->andWhere('le.searchRequest = :searchRequest')
            ->setParameter('searchRequest', $searchRequest);

        $selectedCategory = $filter->resolvedCategory();
        if (null !== $selectedCategory) {
            $qb->andWhere('le.category = :category')
                ->setParameter('category', $selectedCategory->value);
        } elseif (!$filter->showPass) {
            $qb->andWhere('le.category IN (:defaultCategories)')
                ->setParameter('defaultCategories', [DealCategory::MustBuy->value, DealCategory::Good->value]);
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

        $qb->orderBy('le.scoreOutOf100', 'DESC')
            ->addOrderBy('l.price', 'ASC');

        /** @var list<ListingEvaluation> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }
}

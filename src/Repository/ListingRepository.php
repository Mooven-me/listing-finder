<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Listing;
use App\Entity\SearchRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Listing>
 */
class ListingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Listing::class);
    }

    public function findOneBySearchProviderAndExternalId(
        SearchRequest $searchRequest,
        string $provider,
        string $externalId,
    ): ?Listing {
        return $this->findOneBy([
            'searchRequest' => $searchRequest,
            'provider' => $provider,
            'externalId' => $externalId,
        ]);
    }
}

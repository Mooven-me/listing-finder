<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SearchRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SearchRequest>
 */
class SearchRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SearchRequest::class);
    }

    public function findByIdentifierOrLatest(?string $identifier = null): ?SearchRequest
    {
        if (null === $identifier || '' === trim($identifier)) {
            return $this->findOneBy([], ['id' => 'DESC']);
        }

        if (ctype_digit($identifier)) {
            $byId = $this->find((int) $identifier);
            if (null !== $byId) {
                return $byId;
            }
        }

        return $this->createQueryBuilder('sr')
            ->andWhere('LOWER(sr.topic) LIKE LOWER(:topic)')
            ->setParameter('topic', '%'.trim($identifier).'%')
            ->orderBy('sr.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<SearchRequest>
     */
    public function findAllOrderedByNewest(): array
    {
        /** @var list<SearchRequest> $results */
        $results = $this->createQueryBuilder('sr')
            ->leftJoin('sr.cronTask', 'ct')
            ->addSelect('ct')
            ->orderBy('sr.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $results;
    }
}

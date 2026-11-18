<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CronTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CronTask>
 */
class CronTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CronTask::class);
    }

    /**
     * @return list<CronTask>
     */
    public function findDueTasks(?\DateTimeImmutable $now = null): array
    {
        $referenceTime = $now ?? new \DateTimeImmutable();

        /** @var list<CronTask> $tasks */
        $tasks = $this->createQueryBuilder('ct')
            ->innerJoin('ct.searchRequest', 'sr')
            ->addSelect('sr')
            ->andWhere('ct.nextRunAt <= :now')
            ->setParameter('now', $referenceTime)
            ->orderBy('ct.nextRunAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $tasks;
    }

    /**
     * @return list<CronTask>
     */
    public function findAllOrdered(): array
    {
        /** @var list<CronTask> $tasks */
        $tasks = $this->createQueryBuilder('ct')
            ->innerJoin('ct.searchRequest', 'sr')
            ->addSelect('sr')
            ->orderBy('ct.nextRunAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $tasks;
    }
}

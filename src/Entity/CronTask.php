<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CronTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CronTaskRepository::class)]
#[ORM\Table(name: 'cron_task')]
#[ORM\UniqueConstraint(name: 'uniq_cron_task_search_request', columns: ['search_request_id'])]
class CronTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: SearchRequest::class, inversedBy: 'cronTask')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SearchRequest $searchRequest;

    #[ORM\Column(type: Types::INTEGER)]
    private int $intervalMinutes;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $limitPerSubTopic;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $nextRunAt;

    #[ORM\Column(type: Types::INTEGER)]
    private int $runCount = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        SearchRequest $searchRequest,
        int $intervalMinutes = 30,
        ?int $limitPerSubTopic = 25,
        ?\DateTimeImmutable $now = null,
    ) {
        $currentTime = $now ?? new \DateTimeImmutable();
        $this->searchRequest = $searchRequest;
        $this->intervalMinutes = max(1, $intervalMinutes);
        $this->limitPerSubTopic = null !== $limitPerSubTopic && $limitPerSubTopic > 0 ? $limitPerSubTopic : null;
        $this->createdAt = $currentTime;
        $this->lastRunAt = $currentTime;
        $this->nextRunAt = $currentTime->modify(sprintf('+%d minutes', $this->intervalMinutes));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSearchRequest(): SearchRequest
    {
        return $this->searchRequest;
    }

    public function getIntervalMinutes(): int
    {
        return $this->intervalMinutes;
    }

    public function setIntervalMinutes(int $intervalMinutes): self
    {
        $this->intervalMinutes = max(1, $intervalMinutes);

        return $this;
    }

    public function getLimitPerSubTopic(): ?int
    {
        return $this->limitPerSubTopic;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getNextRunAt(): \DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(\DateTimeImmutable $nextRunAt): self
    {
        $this->nextRunAt = $nextRunAt;

        return $this;
    }

    public function getRunCount(): int
    {
        return $this->runCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function markExecuted(?\DateTimeImmutable $now = null): self
    {
        $executedAt = $now ?? new \DateTimeImmutable();
        $this->lastRunAt = $executedAt;
        $this->nextRunAt = $executedAt->modify(sprintf('+%d minutes', $this->intervalMinutes));
        ++$this->runCount;

        return $this;
    }
}

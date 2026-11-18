<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SearchStatus;
use App\Repository\SearchRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SearchRequestRepository::class)]
#[ORM\Table(name: 'search_request')]
class SearchRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $topic;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $userRequirements;

    #[ORM\Column(type: Types::FLOAT)]
    private float $targetPrice;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enableImages;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $generatedSubTopics = [];

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $enabledProviders = [];

    #[ORM\Column(length: 32, enumType: SearchStatus::class)]
    private SearchStatus $status = SearchStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, Listing>
     */
    #[ORM\OneToMany(targetEntity: Listing::class, mappedBy: 'searchRequest', orphanRemoval: true)]
    private Collection $listings;

    /**
     * @var Collection<int, ListingEvaluation>
     */
    #[ORM\OneToMany(targetEntity: ListingEvaluation::class, mappedBy: 'searchRequest', orphanRemoval: true)]
    private Collection $evaluations;

    #[ORM\OneToOne(targetEntity: CronTask::class, mappedBy: 'searchRequest', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?CronTask $cronTask = null;

    /**
     * @param list<string> $enabledProviders
     */
    public function __construct(
        string $topic,
        float $targetPrice,
        bool $enableImages = false,
        ?string $userRequirements = null,
        array $enabledProviders = [],
    ) {
        $this->topic = $topic;
        $this->targetPrice = $targetPrice;
        $this->enableImages = $enableImages;
        $this->userRequirements = $userRequirements;
        $this->enabledProviders = $enabledProviders;
        $this->createdAt = new \DateTimeImmutable();
        $this->listings = new ArrayCollection();
        $this->evaluations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getUserRequirements(): ?string
    {
        return $this->userRequirements;
    }

    public function getTargetPrice(): float
    {
        return $this->targetPrice;
    }

    public function isEnableImages(): bool
    {
        return $this->enableImages;
    }

    /**
     * @return list<string>
     */
    public function getGeneratedSubTopics(): array
    {
        return $this->generatedSubTopics;
    }

    /**
     * @param list<string> $generatedSubTopics
     */
    public function setGeneratedSubTopics(array $generatedSubTopics): self
    {
        $this->generatedSubTopics = array_values(array_unique($generatedSubTopics));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getEnabledProviders(): array
    {
        return $this->enabledProviders;
    }

    public function getStatus(): SearchStatus
    {
        return $this->status;
    }

    public function setStatus(SearchStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, Listing>
     */
    public function getListings(): Collection
    {
        return $this->listings;
    }

    /**
     * @return Collection<int, ListingEvaluation>
     */
    public function getEvaluations(): Collection
    {
        return $this->evaluations;
    }

    public function getCronTask(): ?CronTask
    {
        return $this->cronTask;
    }

    public function setCronTask(?CronTask $cronTask): self
    {
        $this->cronTask = $cronTask;

        return $this;
    }
}

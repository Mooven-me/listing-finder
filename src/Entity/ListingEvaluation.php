<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DealCategory;
use App\Repository\ListingEvaluationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ListingEvaluationRepository::class)]
#[ORM\Table(name: 'listing_evaluation')]
#[ORM\UniqueConstraint(name: 'uniq_eval_search_listing', columns: ['search_request_id', 'listing_id'])]
class ListingEvaluation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SearchRequest::class, inversedBy: 'evaluations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SearchRequest $searchRequest;

    #[ORM\ManyToOne(targetEntity: Listing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Listing $listing;

    #[ORM\Column(type: Types::INTEGER)]
    private int $scoreOutOf100;

    #[ORM\Column(length: 32, enumType: DealCategory::class)]
    private DealCategory $category;

    #[ORM\Column(type: Types::INTEGER)]
    private int $priceScore;

    #[ORM\Column(type: Types::INTEGER)]
    private int $workingConditionScore;

    #[ORM\Column(type: Types::INTEGER)]
    private int $productAgeScore;

    #[ORM\Column(type: Types::INTEGER)]
    private int $relevanceScore;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $isWorking;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $estimatedAge;

    #[ORM\Column(type: Types::TEXT)]
    private string $aiReasoning;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $imageAnalyzed;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $evaluatedAt;

    public function __construct(
        SearchRequest $searchRequest,
        Listing $listing,
        int $scoreOutOf100,
        int $priceScore,
        int $workingConditionScore,
        int $productAgeScore,
        int $relevanceScore,
        string $aiReasoning,
        bool $imageAnalyzed = false,
        ?bool $isWorking = null,
        ?string $estimatedAge = null,
    ) {
        $this->searchRequest = $searchRequest;
        $this->listing = $listing;
        $this->scoreOutOf100 = max(0, min(100, $scoreOutOf100));
        $this->category = DealCategory::fromScore($this->scoreOutOf100);
        $this->priceScore = max(0, min(100, $priceScore));
        $this->workingConditionScore = max(0, min(100, $workingConditionScore));
        $this->productAgeScore = max(0, min(100, $productAgeScore));
        $this->relevanceScore = max(0, min(100, $relevanceScore));
        $this->aiReasoning = $aiReasoning;
        $this->imageAnalyzed = $imageAnalyzed;
        $this->isWorking = $isWorking;
        $this->estimatedAge = $estimatedAge;
        $this->evaluatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSearchRequest(): SearchRequest
    {
        return $this->searchRequest;
    }

    public function getListing(): Listing
    {
        return $this->listing;
    }

    public function getScoreOutOf100(): int
    {
        return $this->scoreOutOf100;
    }

    public function getScoreOutOf10(): float
    {
        return round($this->scoreOutOf100 / 10, 1);
    }

    public function getCategory(): DealCategory
    {
        return $this->category;
    }

    public function getPriceScore(): int
    {
        return $this->priceScore;
    }

    public function getWorkingConditionScore(): int
    {
        return $this->workingConditionScore;
    }

    public function getProductAgeScore(): int
    {
        return $this->productAgeScore;
    }

    public function getRelevanceScore(): int
    {
        return $this->relevanceScore;
    }

    public function isWorking(): ?bool
    {
        return $this->isWorking;
    }

    public function getEstimatedAge(): ?string
    {
        return $this->estimatedAge;
    }

    public function getAiReasoning(): string
    {
        return $this->aiReasoning;
    }

    public function isImageAnalyzed(): bool
    {
        return $this->imageAnalyzed;
    }

    public function getEvaluatedAt(): \DateTimeImmutable
    {
        return $this->evaluatedAt;
    }
}

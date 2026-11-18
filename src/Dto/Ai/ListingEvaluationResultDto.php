<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ListingEvaluationResultDto
{
    public function __construct(
        #[Assert\Range(min: 0, max: 100)]
        public int $score = 50,
        #[SerializedName('price_score')]
        #[Assert\Range(min: 0, max: 100)]
        public int $priceScore = 50,
        #[SerializedName('working_condition_score')]
        #[Assert\Range(min: 0, max: 100)]
        public int $workingConditionScore = 50,
        #[SerializedName('product_age_score')]
        #[Assert\Range(min: 0, max: 100)]
        public int $productAgeScore = 50,
        #[SerializedName('relevance_score')]
        #[Assert\Range(min: 0, max: 100)]
        public int $relevanceScore = 50,
        #[SerializedName('is_working')]
        public ?bool $isWorking = true,
        #[SerializedName('estimated_age')]
        public ?string $estimatedAge = null,
        #[Assert\NotBlank]
        public string $reasoning = 'Evaluated based on listing attributes.',
        public bool $imageAnalyzed = false,
    ) {
    }

    public function withImageAnalyzed(bool $imageAnalyzed): self
    {
        return new self(
            score: $this->score,
            priceScore: $this->priceScore,
            workingConditionScore: $this->workingConditionScore,
            productAgeScore: $this->productAgeScore,
            relevanceScore: $this->relevanceScore,
            isWorking: $this->isWorking,
            estimatedAge: $this->estimatedAge,
            reasoning: $this->reasoning,
            imageAnalyzed: $imageAnalyzed,
        );
    }
}

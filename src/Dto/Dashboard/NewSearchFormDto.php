<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class NewSearchFormDto
{
    /**
     * @param list<string> $providers
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Please enter a product or search topic.')]
        #[Assert\Length(min: 2, max: 255)]
        public string $topic = '',
        #[Assert\Positive(message: 'Target price must be greater than 0.')]
        public float $targetPrice = 50.0,
        public ?string $userRequirements = null,
        public bool $enableImages = false,
        #[Assert\Range(min: 1, max: 100)]
        public int $limitPerSubTopic = 25,
        #[Assert\Range(min: 5, max: 1440)]
        public int $intervalMinutes = 30,
        #[Assert\Count(min: 1)]
        public array $providers = ['leboncoin', 'vinted', 'backmarket'],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use App\Enum\DealCategory;

final readonly class DashboardFilterDto
{
    public function __construct(
        public ?int $search = null,
        public string $sort = 'category',
        public ?string $category = null,
        public ?string $provider = null,
        public ?string $subTopic = null,
        public ?string $q = null,
        public ?float $maxPrice = null,
        public bool $onlyWorking = false,
        public bool $showPass = false,
        public string $view = 'cards',
    ) {
    }

    public function isSortByScore(): bool
    {
        return 'score' === strtolower(trim($this->sort));
    }

    public function isTableView(): bool
    {
        return 'table' === strtolower(trim($this->view));
    }

    public function resolvedCategory(): ?DealCategory
    {
        if (null === $this->category || '' === trim($this->category)) {
            return null;
        }

        $normalized = str_replace(['-', ' '], '_', strtolower(trim($this->category)));

        return match ($normalized) {
            'must_buy', 'mustbuy', 'must' => DealCategory::MustBuy,
            'good', 'good_deals', 'good_deal' => DealCategory::Good,
            'pass', 'bad', 'other' => DealCategory::Pass,
            default => null,
        };
    }

    public function normalizedProvider(): ?string
    {
        if (null === $this->provider || '' === trim($this->provider)) {
            return null;
        }

        return strtolower(trim($this->provider));
    }

    public function normalizedSubTopic(): ?string
    {
        if (null === $this->subTopic || '' === trim($this->subTopic)) {
            return null;
        }

        return trim($this->subTopic);
    }

    public function normalizedQuery(): ?string
    {
        if (null === $this->q || '' === trim($this->q)) {
            return null;
        }

        return trim($this->q);
    }
}

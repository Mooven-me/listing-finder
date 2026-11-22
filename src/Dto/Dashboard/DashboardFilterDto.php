<?php

declare(strict_types=1);

namespace App\Dto\Dashboard;

use App\Enum\DealCategory;

final readonly class DashboardFilterDto
{
    public const SORT_CATEGORY = 'category';
    public const SORT_SCORE = 'score';
    public const SORT_PRICE_ASC = 'price_asc';
    public const SORT_PRICE_DESC = 'price_desc';
    public const SORT_NEWEST = 'newest';

    public function __construct(
        public ?int $search = null,
        public string $sort = self::SORT_CATEGORY,
        public ?string $category = null,
        public ?string $provider = null,
        public ?string $subTopic = null,
        public ?string $q = null,
        public ?float $maxPrice = null,
        public bool $onlyWorking = false,
        public bool $showPoor = false,
        public bool $onlyNew = false,
        public bool $showSold = false,
        public string $view = 'cards',
        public int $page = 1,
        public int $perPage = 20,
    ) {
    }

    public function normalizedSort(): string
    {
        return match (strtolower(trim($this->sort))) {
            self::SORT_SCORE => self::SORT_SCORE,
            self::SORT_PRICE_ASC => self::SORT_PRICE_ASC,
            self::SORT_PRICE_DESC => self::SORT_PRICE_DESC,
            self::SORT_NEWEST, 'date', 'date_desc' => self::SORT_NEWEST,
            default => self::SORT_CATEGORY,
        };
    }

    public function isGroupedByCategory(): bool
    {
        return self::SORT_CATEGORY === $this->normalizedSort();
    }

    public function isSortByScore(): bool
    {
        return self::SORT_SCORE === $this->normalizedSort();
    }

    public function isTableView(): bool
    {
        return 'table' === strtolower(trim($this->view));
    }

    public function normalizedPage(): int
    {
        return max(1, $this->page);
    }

    public function normalizedPerPage(): int
    {
        return \in_array($this->perPage, [10, 20, 50, 100], true) ? $this->perPage : 20;
    }

    public function resolvedCategory(): ?DealCategory
    {
        if (null === $this->category || '' === trim($this->category)) {
            return null;
        }

        $normalized = str_replace(['-', ' '], '_', strtolower(trim($this->category)));

        return match ($normalized) {
            'must_buy', 'mustbuy', 'must' => DealCategory::MustBuy,
            'extremely_good', 'extremelygood', 'ext_good' => DealCategory::ExtremelyGood,
            'good', 'good_deals', 'good_deal' => DealCategory::Good,
            'bad' => DealCategory::Bad,
            'poor', 'pass', 'other' => DealCategory::Poor,
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

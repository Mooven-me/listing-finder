<?php

declare(strict_types=1);

namespace App\Enum;

enum DealCategory: string
{
    case MustBuy = 'must_buy';
    case ExtremelyGood = 'extremely_good';
    case Good = 'good';
    case Bad = 'bad';
    case Poor = 'poor';

    public static function fromScore(int $scoreOutOf100): self
    {
        return match (true) {
            $scoreOutOf100 >= 90 => self::MustBuy,
            $scoreOutOf100 >= 70 => self::ExtremelyGood,
            $scoreOutOf100 >= 50 => self::Good,
            $scoreOutOf100 >= 30 => self::Bad,
            default => self::Poor,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MustBuy => 'MUST BUY (90-100)',
            self::ExtremelyGood => 'EXTREMELY GOOD (70-89)',
            self::Good => 'GOOD (50-69)',
            self::Bad => 'BAD (30-49)',
            self::Poor => 'POOR (0-29)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::MustBuy => 'Must Buy',
            self::ExtremelyGood => 'Extremely Good',
            self::Good => 'Good',
            self::Bad => 'Bad',
            self::Poor => 'Poor',
        };
    }
}

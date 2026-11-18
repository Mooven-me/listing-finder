<?php

declare(strict_types=1);

namespace App\Enum;

enum DealCategory: string
{
    case MustBuy = 'must_buy';
    case Good = 'good';
    case Pass = 'pass';

    public static function fromScore(int $scoreOutOf100): self
    {
        return match (true) {
            $scoreOutOf100 >= 90 => self::MustBuy,
            $scoreOutOf100 >= 70 => self::Good,
            default => self::Pass,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MustBuy => 'MUST BUY (9-10/10)',
            self::Good => 'GOOD (7-8/10)',
            self::Pass => 'PASS (<7/10)',
        };
    }
}

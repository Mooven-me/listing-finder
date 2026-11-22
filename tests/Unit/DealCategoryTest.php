<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\DealCategory;
use PHPUnit\Framework\TestCase;

final class DealCategoryTest extends TestCase
{
    public function testFromScoreMapsAllFiveTiersCorrectly(): void
    {
        self::assertSame(DealCategory::MustBuy, DealCategory::fromScore(100));
        self::assertSame(DealCategory::MustBuy, DealCategory::fromScore(90));
        self::assertSame(DealCategory::ExtremelyGood, DealCategory::fromScore(89));
        self::assertSame(DealCategory::ExtremelyGood, DealCategory::fromScore(70));
        self::assertSame(DealCategory::Good, DealCategory::fromScore(69));
        self::assertSame(DealCategory::Good, DealCategory::fromScore(50));
        self::assertSame(DealCategory::Bad, DealCategory::fromScore(49));
        self::assertSame(DealCategory::Bad, DealCategory::fromScore(30));
        self::assertSame(DealCategory::Poor, DealCategory::fromScore(29));
        self::assertSame(DealCategory::Poor, DealCategory::fromScore(0));
    }

    public function testLabelsContainExpectedRanges(): void
    {
        self::assertStringContainsString('MUST BUY', DealCategory::MustBuy->label());
        self::assertStringContainsString('EXTREMELY GOOD', DealCategory::ExtremelyGood->label());
        self::assertStringContainsString('GOOD', DealCategory::Good->label());
        self::assertStringContainsString('BAD', DealCategory::Bad->label());
        self::assertStringContainsString('POOR', DealCategory::Poor->label());
    }
}

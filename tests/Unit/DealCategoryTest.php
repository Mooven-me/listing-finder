<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\DealCategory;
use PHPUnit\Framework\TestCase;

final class DealCategoryTest extends TestCase
{
    public function testFromScoreMapsMustBuyGoodAndPassCorrectly(): void
    {
        self::assertSame(DealCategory::MustBuy, DealCategory::fromScore(100));
        self::assertSame(DealCategory::MustBuy, DealCategory::fromScore(90));
        self::assertSame(DealCategory::Good, DealCategory::fromScore(89));
        self::assertSame(DealCategory::Good, DealCategory::fromScore(70));
        self::assertSame(DealCategory::Pass, DealCategory::fromScore(69));
        self::assertSame(DealCategory::Pass, DealCategory::fromScore(0));
    }

    public function testLabelsContainExpectedRanges(): void
    {
        self::assertStringContainsString('MUST BUY', DealCategory::MustBuy->label());
        self::assertStringContainsString('GOOD', DealCategory::Good->label());
        self::assertStringContainsString('PASS', DealCategory::Pass->label());
    }
}

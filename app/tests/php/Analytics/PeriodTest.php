<?php

declare(strict_types=1);

namespace Petitstats\Tests\Analytics;

use Petitstats\Analytics\Period;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    /** 2026-03-15 12:00:00 UTC */
    private const NOW = 1773576000;

    public function testPresetsEndNow(): void
    {
        $period = Period::from('7d', now: self::NOW);

        $this->assertSame('7d', $period->range);
        $this->assertSame(self::NOW - 7 * 86400, $period->start);
        $this->assertSame(self::NOW, $period->end);
    }

    public function testTodayStartsAtMidnightUtc(): void
    {
        $this->assertSame('2026-03-15 00:00:00', Period::from('today', now: self::NOW)->startText());
    }

    public function testAllStartsAtTheEpoch(): void
    {
        $period = Period::from('all', now: self::NOW);

        $this->assertTrue($period->isAll());
        $this->assertSame(0, $period->start);
    }

    public function testUnknownRangeFallsBackToSevenDays(): void
    {
        $this->assertSame(self::NOW - 7 * 86400, Period::from('forever', now: self::NOW)->start);
    }

    public function testCustomPeriodSpansWholeUtcDays(): void
    {
        $period = Period::from('custom', '2026-03-01', '2026-03-10', self::NOW);

        $this->assertSame('custom', $period->range);
        $this->assertSame('2026-03-01 00:00:00', $period->startText());
        $this->assertSame('2026-03-10 23:59:59', $period->endText());
    }

    public function testCustomPeriodAcceptsDatesInEitherOrder(): void
    {
        $period = Period::from('custom', '2026-03-10', '2026-03-01', self::NOW);

        $this->assertSame('2026-03-01 00:00:00', $period->startText());
        $this->assertSame('2026-03-10 23:59:59', $period->endText());
    }

    public function testRequestWithBothDatesIsCustom(): void
    {
        $period = Period::fromRequest(['range' => '30d', 'from' => '2026-03-01', 'to' => '2026-03-02'], self::NOW);

        $this->assertSame('custom', $period->range);
    }

    public function testPreviousPeriodHasTheSameLength(): void
    {
        $period = Period::from('7d', now: self::NOW);

        $this->assertSame($period->start - 7 * 86400, $period->previousStart());
    }
}

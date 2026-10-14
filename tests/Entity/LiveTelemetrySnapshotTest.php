<?php

namespace App\Tests\Entity;

use App\Entity\LiveTelemetrySnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the exact (non-compact) currency formatter used in the incident
 * modal and the dilution/covenant stat boxes — distinct from formatPence()'s
 * £K/£M compact style, which would misrepresent a precise contract value.
 */
class LiveTelemetrySnapshotTest extends TestCase
{
    public function testFormatsExactPoundsWithNoRounding(): void
    {
        $this->assertSame('£31,000', LiveTelemetrySnapshot::formatPenceExact(3100000));
    }

    public function testStaysExactAtAMagnitudeThatFormatPenceWouldRoundToCompactForm(): void
    {
        // formatPence(120000000) would compactly render as "£1.2M" — formatPenceExact must not.
        $this->assertSame('£1,200,000', LiveTelemetrySnapshot::formatPenceExact(120_000_000));
    }

    public function testHandlesZero(): void
    {
        $this->assertSame('£0', LiveTelemetrySnapshot::formatPenceExact(0));
    }

    public function testSignsNegativeAmounts(): void
    {
        $this->assertSame('-£4,500', LiveTelemetrySnapshot::formatPenceExact(-450000));
    }

    public function testRoundsSubPoundFractionsToWholePounds(): void
    {
        $this->assertSame('£1,320', LiveTelemetrySnapshot::formatPenceExact(132024));
    }

    /**
     * The 3rd stat box's sub-line. Always two decimals — including the zero-match case,
     * where the spec calls for "0.00 G/Match" rather than an empty or dashed value — so the
     * line keeps a constant width in the fixed-width terminal font.
     */
    public function testGoalsPerMatchFormatsToTwoDecimals(): void
    {
        $snapshot = new LiveTelemetrySnapshot();
        $snapshot->update(0, 0, 0, 0, 0, 0, 0, [], null, 214, 75, 18);

        $this->assertSame('2.85 G/Match', $snapshot->getGoalsPerMatchFormatted());
        $this->assertSame(214, $snapshot->getTotalGoals());
        $this->assertSame(18, $snapshot->getTableTurnover());
    }

    /** Zero matches must not divide by zero, and must still render two decimals. */
    public function testGoalsPerMatchHandlesZeroMatches(): void
    {
        $snapshot = new LiveTelemetrySnapshot();
        $snapshot->update(0, 0, 0, 0, 0, 0, 0, [], null, 0, 0, 0);

        $this->assertSame('0.00 G/Match', $snapshot->getGoalsPerMatchFormatted());
        $this->assertSame(0, $snapshot->getTotalGoals());
    }

    /** A fresh snapshot renders cleanly before the first generate run. */
    public function testDefaultsRenderWithoutAnUpdate(): void
    {
        $snapshot = new LiveTelemetrySnapshot();

        $this->assertSame(0, $snapshot->getTotalGoals());
        $this->assertSame(0, $snapshot->getTableTurnover());
        $this->assertSame('0.00 G/Match', $snapshot->getGoalsPerMatchFormatted());
    }
}

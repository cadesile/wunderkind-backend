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
}

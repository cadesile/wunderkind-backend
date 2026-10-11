<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ClubSpotlight;
use PHPUnit\Framework\TestCase;

class ClubSpotlightWindowTest extends TestCase
{
    private function spotlight(string $clubName, string $endsAt): ClubSpotlight
    {
        $s = new ClubSpotlight();
        $s->update(
            clubName: $clubName,
            clubReputation: 0,
            dividendDrawsPence: 0,
            homeKitConfig: null,
            awayKitConfig: null,
            badgeConfig: null,
            ownerName: null,
            ownerNationality: null,
            ownerAppearance: null,
            stadiumConfig: null,
            recentFixtures: [],
            recentTransfers: [],
            topPerformerName: null,
            topPerformerGoals: 0,
            topPerformerAssists: 0,
            topPerformerAppearanceConfig: null,
            windowStartsAt: new \DateTimeImmutable('-1 hour'),
            windowEndsAt: new \DateTimeImmutable($endsAt),
        );

        return $s;
    }

    public function testAFreshSpotlightIsLive(): void
    {
        $this->assertTrue($this->spotlight('Live FC', '+11 hours')->isLive());
    }

    public function testAnExpiredWindowIsNotLive(): void
    {
        // The card claims "FEATURED THIS CYCLE" and the note promises a 12-hourly
        // pick, so a row left behind by a stopped cron must stop rendering.
        $this->assertFalse($this->spotlight('Stale FC', '-2 days')->isLive());
    }

    public function testANeverGeneratedSpotlightIsNotLive(): void
    {
        $this->assertFalse((new ClubSpotlight())->isLive());
    }

    public function testAnEmptyClubNameIsNotLiveEvenInsideTheWindow(): void
    {
        $this->assertFalse($this->spotlight('', '+11 hours')->isLive());
    }

    public function testExtendWindowRevivesAnExpiredRowWithoutTouchingItsContent(): void
    {
        $s = $this->spotlight('Quiet FC', '-1 hour');
        $this->assertFalse($s->isLive());

        $s->extendWindow(new \DateTimeImmutable('+12 hours'));

        $this->assertTrue($s->isLive());
        $this->assertSame('Quiet FC', $s->getClubName(), 'extendWindow() must not disturb the feature itself.');
    }
}

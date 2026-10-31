<?php

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use PHPUnit\Framework\TestCase;

/**
 * The academy scholarship wage: the unit, the floor, and the default the client depends on.
 */
class GameConfigYouthScholarshipTest extends TestCase
{
    /**
     * The figure itself, pinned ONCE. Everything else on the backend reads the constant —
     * the column default, SyncService's no-config branch, the admin form's POST default —
     * so this is the only assertion that should ever need changing to reprice the default.
     */
    public function testTheDefaultIsTwoHundredAndFiftyPoundsInPence(): void
    {
        // The client falls back to this exact figure when the field is absent (an older
        // backend, or a config cached before it shipped), so the two must not drift.
        $this->assertSame(25000, GameConfig::DEFAULT_YOUTH_SCHOLARSHIP_WAGE_PENCE);
    }

    public function testAFreshConfigStartsOnThatDefault(): void
    {
        $this->assertSame(
            GameConfig::DEFAULT_YOUTH_SCHOLARSHIP_WAGE_PENCE,
            (new GameConfig())->getYouthScholarshipWagePence(),
        );
    }

    public function testFloorsAtZeroRatherThanAcceptingANegativeWage(): void
    {
        $config = new GameConfig();
        $config->setYouthScholarshipWagePence(-5000);

        // A negative wage would pay the club to sign a child.
        $this->assertSame(0, $config->getYouthScholarshipWagePence());
    }

    public function testStoresWhatItIsGiven(): void
    {
        $config = new GameConfig();
        $config->setYouthScholarshipWagePence(40000);

        $this->assertSame(40000, $config->getYouthScholarshipWagePence());
    }

    /**
     * The admin form takes POUNDS and the column holds PENCE, so DashboardController
     * multiplies by 100 on save and the Twig input divides by 100 to display. This pins the
     * round trip: the exact shape a stray or missing `* 100` breaks, which has caused a real
     * bug in this product before.
     */
    public function testAdminPoundsRoundTripThroughPence(): void
    {
        $poundsEnteredByAdmin = 250;

        $config = new GameConfig();
        $config->setYouthScholarshipWagePence($poundsEnteredByAdmin * 100); // DashboardController

        $this->assertSame(GameConfig::DEFAULT_YOUTH_SCHOLARSHIP_WAGE_PENCE, $config->getYouthScholarshipWagePence());
        $this->assertSame($poundsEnteredByAdmin, intdiv($config->getYouthScholarshipWagePence(), 100)); // the Twig input
    }
}

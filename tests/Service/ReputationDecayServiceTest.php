<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\User;
use App\Service\ReputationDecayService;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for dormancy-based reputation decay. Pure arithmetic over one Club,
 * so no database — app:reputation:decay owns iteration and persistence.
 *
 * The properties under test are the ones that make the single command safe as BOTH
 * a nightly cron and a one-off backfill over every historical club: whole-day
 * charging, catch-up across missed runs, idempotency, remainder carry, and the
 * unsigned-column floor at zero.
 */
class ReputationDecayServiceTest extends TestCase
{
    private const NOW = '2026-10-12 12:00:00';

    private function service(): ReputationDecayService
    {
        return new ReputationDecayService();
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    /**
     * Club::$createdAt and $lastSyncedAt have no public setters (createdAt is set in
     * the constructor), so the fixture is built through reflection rather than by
     * adding test-only setters to a production entity.
     */
    private function club(int $reputation, ?string $lastSyncedAt, ?string $createdAt = null, ?string $decayedAt = null): Club
    {
        $club = new Club('Test Club', new User('decay@test.local'));
        $club->setReputation($reputation);

        if ($lastSyncedAt !== null) {
            $club->setLastSyncedAt(new \DateTimeImmutable($lastSyncedAt));
        }
        if ($decayedAt !== null) {
            $club->setReputationDecayedAt(new \DateTimeImmutable($decayedAt));
        }

        $createdAtProp = new \ReflectionProperty(Club::class, 'createdAt');
        $createdAtProp->setValue($club, new \DateTimeImmutable($createdAt ?? '2026-01-01 00:00:00'));

        return $club;
    }

    public function testChargesFivePointsPerWholeDaySinceLastSync(): void
    {
        $club = $this->club(100, '2026-10-09 12:00:00');

        $lost = $this->service()->apply($club, $this->now());

        $this->assertSame(15, $lost, '3 days dormant at 5 points a day');
        $this->assertSame(85, $club->getReputation());
    }

    public function testChargesNothingBeforeAFullDayHasPassed(): void
    {
        $club = $this->club(100, '2026-10-11 13:00:00'); // 23 hours ago

        $this->assertSame(0, $this->service()->apply($club, $this->now()));
        $this->assertSame(100, $club->getReputation());
    }

    public function testASecondRunTheSameDayChargesNothing(): void
    {
        $service = $this->service();
        $club    = $this->club(100, '2026-10-09 12:00:00');

        $service->apply($club, $this->now());
        $this->assertSame(0, $service->apply($club, $this->now()), 'running twice must not double-charge');
        $this->assertSame(85, $club->getReputation());
    }

    /**
     * The whole reason there is no separate backfill command: one pass settles a club
     * that has been silent for months, with the same arithmetic as a nightly run.
     */
    public function testSettlesEveryMissedDayInASinglePass(): void
    {
        $club = $this->club(500, '2026-08-13 12:00:00'); // 60 days

        $this->assertSame(300, $this->service()->apply($club, $this->now()));
        $this->assertSame(200, $club->getReputation());
    }

    public function testFloorsAtZeroRatherThanGoingNegative(): void
    {
        // club.reputation is an unsigned bigint — a negative value would not persist.
        $club = $this->club(10, '2026-08-13 12:00:00');

        $this->assertSame(10, $this->service()->apply($club, $this->now()));
        $this->assertSame(0, $club->getReputation());
    }

    public function testCarriesThePartDayRemainderRatherThanDiscardingIt(): void
    {
        $service = $this->service();
        // 1 day and 18 hours dormant: one day is charged now, and the 18-hour
        // remainder must survive so the second day lands 6 hours later, not 24.
        $club = $this->club(100, '2026-10-10 18:00:00');

        $this->assertSame(5, $service->apply($club, $this->now()));
        $this->assertSame(5, $service->apply($club, $this->now()->modify('+6 hours')));
        $this->assertSame(90, $club->getReputation());
    }

    public function testAClubThatHasNeverSyncedDecaysFromItsFoundingDate(): void
    {
        $club = $this->club(100, null, '2026-10-02 12:00:00');

        $this->assertSame(50, $this->service()->apply($club, $this->now()), '10 days since founding');
    }

    public function testSyncingResetsTheClockEvenWithAnOlderDecayAnchor(): void
    {
        // The anchor is max(lastSyncedAt, reputationDecayedAt, createdAt), so a club
        // that synced after its last decay owes nothing for the days in between.
        $club = $this->club(100, '2026-10-12 06:00:00', '2026-01-01 00:00:00', '2026-09-01 00:00:00');

        $this->assertSame(0, $this->service()->apply($club, $this->now()));
        $this->assertSame(100, $club->getReputation());
    }

    public function testAdvancesTheAnchorEvenWhenTheClubIsAlreadyAtZero(): void
    {
        // Otherwise every floored club re-walks its whole dormant history on every
        // nightly run, forever.
        $club = $this->club(0, '2026-08-13 12:00:00');

        $this->assertSame(0, $this->service()->apply($club, $this->now()));
        $this->assertNotNull($club->getReputationDecayedAt());
        $this->assertSame(0, $this->service()->dormantDays($club, $this->now()));
    }

    public function testFutureAnchorsNeverProduceNegativeDays(): void
    {
        // Clock skew between the app server and a client's reported sync time should
        // read as "not yet dormant", never as a reputation refund.
        $club = $this->club(100, '2026-10-20 12:00:00');

        $this->assertSame(0, $this->service()->dormantDays($club, $this->now()));
        $this->assertSame(0, $this->service()->apply($club, $this->now()));
        $this->assertSame(100, $club->getReputation());
    }
}

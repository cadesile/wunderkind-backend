<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Club;
use App\Entity\Location\ClubDormancyWarning;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\PlotClaim;
use App\Entity\User;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\Location\DormancyWarningStage;
use App\Enum\UserLedgerEntryType;
use App\Repository\Location\ClubDormancyWarningRepository;
use App\Repository\UserLedgerRepository;
use App\Service\Location\PlotClaimService;
use App\Service\Location\PlotHoldService;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:plots:dormancy-warn and app:plots:dormancy-reclaim.
 *
 * The assertion this file exists for is that **an unwarned plot is never reclaimed**. Taking
 * land a player paid real credit for, without having told them, is the failure that would turn
 * a dormancy rule into a support incident — so the reclaim's refusal to act on an unwarned or
 * undelivered cycle is tested from both sides.
 */
class DormancyCommandsTest extends KernelTestCase
{
    private const COUNTRY = 'ZY';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Application $app;
    private ClubDormancyWarningRepository $warnings;
    private UserLedgerRepository $ledger;

    protected function setUp(): void
    {
        self::bootKernel();

        $c = self::getContainer();

        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);
        $this->warnings   = $c->get(ClubDormancyWarningRepository::class);
        $this->ledger     = $c->get(UserLedgerRepository::class);
        $this->app        = new Application(self::$kernel);

        $this->purge();

        $country = new CountryProfile(self::COUNTRY, 'Dormland');
        $country->setStatus(CountryProfileStatus::LIVE);
        $this->em->persist($country);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    // ───────────────────────── the stage schedule ─────────────────────────

    public function testStageThresholdsAreDerivedFromTheDeadline(): void
    {
        // Derived, not written out, so moving the deadline cannot leave a warning firing after
        // the reclaim it is supposed to precede.
        $this->assertSame(23, DormancyWarningStage::N_MINUS_7->triggersAfterDays());
        $this->assertSame(29, DormancyWarningStage::N_MINUS_1->triggersAfterDays());
        $this->assertSame(30, DormancyWarningStage::RECLAIM_AFTER_DAYS);
    }

    public function testTheMostUrgentApplicableStageIsChosen(): void
    {
        $this->assertNull(DormancyWarningStage::forDaysInactive(22), 'too early to warn');
        $this->assertSame(DormancyWarningStage::N_MINUS_7, DormancyWarningStage::forDaysInactive(23));
        $this->assertSame(DormancyWarningStage::N_MINUS_7, DormancyWarningStage::forDaysInactive(28));

        // A missed cron tick must not send a stale seven-day warning to someone with one day
        // left — they need the truth.
        $this->assertSame(DormancyWarningStage::N_MINUS_1, DormancyWarningStage::forDaysInactive(29));
        $this->assertSame(DormancyWarningStage::N_MINUS_1, DormancyWarningStage::forDaysInactive(45));
    }

    // ───────────────────────── warning ─────────────────────────

    public function testAQuietClubIsWarnedAndTheWarningIsRecordedAsSent(): void
    {
        $claim = $this->dormantClaim(daysInactive: 24);

        $this->runDormancy('app:plots:dormancy-warn');

        $warning = $this->em->getRepository(ClubDormancyWarning::class)->findOneBy(['claim' => $claim]);

        $this->assertNotNull($warning);
        $this->assertSame(DormancyWarningStage::N_MINUS_7, $warning->getStage());
        $this->assertTrue($warning->isSent(), 'the row must be stamped once delivered');
        $this->assertNotNull($warning->getInboxMessageId(), 'and carry its delivery receipt');
    }

    public function testAnActiveClubIsNotWarned(): void
    {
        $this->dormantClaim(daysInactive: 3);

        $output = $this->runDormancy('app:plots:dormancy-warn');

        $this->assertStringContainsString('No clubs are inactive enough', $output);
    }

    public function testRunningTwiceDoesNotWarnTwice(): void
    {
        $claim = $this->dormantClaim(daysInactive: 24);

        $this->runDormancy('app:plots:dormancy-warn');
        $this->runDormancy('app:plots:dormancy-warn');

        // ON CONFLICT DO NOTHING is the dedupe, so an overlapping cron tick is harmless.
        $this->assertCount(
            1,
            $this->em->getRepository(ClubDormancyWarning::class)->findBy(['claim' => $claim]),
        );
    }

    public function testARelapseIsWarnedAgainBecauseTheCycleKeyChanges(): void
    {
        $claim = $this->dormantClaim(daysInactive: 24);

        $this->runDormancy('app:plots:dormancy-warn');
        $this->assertCount(1, $this->em->getRepository(ClubDormancyWarning::class)->findBy(['claim' => $claim]));

        // The player comes back, plays, then goes quiet again — a NEW activity timestamp and so
        // a new dormancy cycle. Keyed on (claim, stage) alone this would be silenced for ever.
        $this->setLastActive($claim->getClub(), daysInactive: 24, offsetDays: 40);
        $this->em->clear();

        $this->runDormancy('app:plots:dormancy-warn');

        $reloaded = $this->em->find(PlotClaim::class, $claim->getId());
        $this->assertCount(
            2,
            $this->em->getRepository(ClubDormancyWarning::class)->findBy(['claim' => $reloaded]),
            'a fresh lapse gets fresh warnings',
        );
    }

    public function testDryRunSendsNothingAndWritesNoRow(): void
    {
        $claim = $this->dormantClaim(daysInactive: 24);

        $output = $this->runDormancy('app:plots:dormancy-warn', ['--dry-run' => true]);

        $this->assertStringContainsString('Dry run', $output);
        $this->assertCount(
            0,
            $this->em->getRepository(ClubDormancyWarning::class)->findBy(['claim' => $claim]),
        );
    }

    // ───────────────────────── reclaiming ─────────────────────────

    public function testAnUnwarnedPlotIsNeverReclaimed(): void
    {
        $claim = $this->dormantClaim(daysInactive: 35);

        // Past the deadline, but nobody ever told them.
        $output = $this->runDormancy('app:plots:dormancy-reclaim');

        $this->assertStringContainsString('not warned this cycle', $output);

        $this->em->clear();
        $this->assertTrue(
            $this->em->find(PlotClaim::class, $claim->getId())->isActive(),
            'THE assertion: an unwarned plot stays with its owner',
        );
    }

    public function testAnUndeliveredWarningDoesNotCountAsWarned(): void
    {
        $claim = $this->dormantClaim(daysInactive: 35);

        // A warning row exists but was never stamped — the send failed. The guard must read
        // sent_at, not mere existence, or a delivery failure costs the player their plot.
        $this->warnings->claimWarning(
            $claim,
            DormancyWarningStage::N_MINUS_1,
            $this->cycleKeyFor($claim),
            new \DateTimeImmutable(),
        );

        $this->runDormancy('app:plots:dormancy-reclaim');

        $this->em->clear();
        $this->assertTrue($this->em->find(PlotClaim::class, $claim->getId())->isActive());
    }

    public function testAWarnedPlotIsReclaimedAndTheOwnerIsCredited(): void
    {
        $claim  = $this->dormantClaim(daysInactive: 35);
        $userId = $claim->getUser()->getId();

        $before = $this->ledger->getCurrentBalance($claim->getUser());

        $this->markWarned($claim);
        $this->runDormancy('app:plots:dormancy-reclaim');

        $this->em->clear();
        $reloaded = $this->em->find(PlotClaim::class, $claim->getId());

        $this->assertFalse($reloaded->isActive());
        $this->assertSame(\App\Enum\Location\ClaimReleaseReason::DORMANCY, $reloaded->getReleaseReason());

        $user = $this->em->find(User::class, $userId);
        $this->assertGreaterThan(
            $before,
            $this->ledger->getCurrentBalance($user),
            'the player loses the plot, not the money',
        );
    }

    public function testReclaimDryRunChangesNothing(): void
    {
        $claim = $this->dormantClaim(daysInactive: 35);
        $this->markWarned($claim);

        $output = $this->runDormancy('app:plots:dormancy-reclaim', ['--dry-run' => true]);

        $this->assertStringContainsString('Dry run', $output);

        $this->em->clear();
        $this->assertTrue($this->em->find(PlotClaim::class, $claim->getId())->isActive());
    }

    public function testForceUnwarnedReclaimsAnyway(): void
    {
        $claim = $this->dormantClaim(daysInactive: 35);

        // The support escape hatch, for a cycle whose warnings are known to have failed.
        $this->runDormancy('app:plots:dormancy-reclaim', ['--force-unwarned' => true]);

        $this->em->clear();
        $this->assertFalse($this->em->find(PlotClaim::class, $claim->getId())->isActive());
    }

    public function testAPlotNotYetPastTheDeadlineIsLeftAlone(): void
    {
        $claim = $this->dormantClaim(daysInactive: 25);
        $this->markWarned($claim);

        $output = $this->runDormancy('app:plots:dormancy-reclaim');

        $this->assertStringContainsString('No plots are due', $output);

        $this->em->clear();
        $this->assertTrue($this->em->find(PlotClaim::class, $claim->getId())->isActive());
    }

    public function testReclaimingTwiceCreditsOnce(): void
    {
        $claim = $this->dormantClaim(daysInactive: 35);
        $this->markWarned($claim);

        $this->runDormancy('app:plots:dormancy-reclaim');

        $this->em->clear();
        $user           = $this->em->find(User::class, $claim->getUser()->getId());
        $afterFirstRun  = $this->ledger->getCurrentBalance($user);

        $this->runDormancy('app:plots:dormancy-reclaim');

        $this->em->clear();
        $user = $this->em->find(User::class, $claim->getUser()->getId());

        $this->assertSame($afterFirstRun, $this->ledger->getCurrentBalance($user), 'paid once');
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @param array<string, mixed> $options */
    private function runDormancy(string $command, array $options = []): string
    {
        $tester = new CommandTester($this->app->find($command));
        $tester->execute($options);

        return $tester->getDisplay();
    }

    private function cycleKeyFor(PlotClaim $claim): string
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COALESCE(last_synced_at, created_at) AS t FROM club WHERE id = :id',
            ['id' => (string) $claim->getClub()->getId()],
        );

        return (new \DateTimeImmutable((string) $row['t']))->format('Y-m-d');
    }

    private function markWarned(PlotClaim $claim): void
    {
        $id = $this->warnings->claimWarning(
            $claim,
            DormancyWarningStage::N_MINUS_1,
            $this->cycleKeyFor($claim),
            new \DateTimeImmutable(),
        );

        $this->warnings->markSent($id, 'test-message', new \DateTimeImmutable());
    }

    private function setLastActive(Club $club, int $daysInactive, int $offsetDays = 0): void
    {
        $this->connection->executeStatement(
            'UPDATE club SET last_synced_at = :t WHERE id = :id',
            [
                't'  => (new \DateTimeImmutable())->modify(sprintf('-%d days', $daysInactive + $offsetDays)),
                'id' => (string) $club->getId(),
            ],
            ['t' => 'datetime_immutable'],
        );
    }

    private function dormantClaim(int $daysInactive, string $town = 'Quietville'): PlotClaim
    {
        $profile  = $this->em->find(CountryProfile::class, self::COUNTRY);
        $location = new Location(
            $profile,
            $town,
            strtolower(self::COUNTRY . '-' . $town . '-' . bin2hex(random_bytes(2))),
            strtolower($town),
        );
        $location->setRegion('R');
        $location->setPopulation(100_000);
        $location->setCoordinates(53.0, -2.0);
        $location->setLandPence(50_000_00);
        $location->setLandValuedAt(new \DateTimeImmutable());

        $this->em->persist($location);

        $plot = new LandPlot($location, 1);
        $this->em->persist($plot);
        $this->em->flush();

        $user = $this->user(creditPence: 10_000_000_00);
        $club = new Club('Quiet FC ' . bin2hex(random_bytes(3)), $user);
        $club->setBalance(1_000_00);
        $this->em->persist($club);
        $this->em->flush();

        $hold  = self::getContainer()->get(PlotHoldService::class)->acquire($user, $plot);
        $claim = self::getContainer()->get(PlotClaimService::class)
            ->claimFromHold($user, (string) $hold->getId());

        self::getContainer()->get(PlotClaimService::class)->attachClub($claim, $club);

        $this->setLastActive($club, $daysInactive);

        return $claim;
    }

    private function user(int $creditPence = 0): User
    {
        $user = new User('dorm-' . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        if ($creditPence > 0) {
            self::getContainer()->get(UserLedgerService::class)->append(
                $user,
                null,
                UserLedgerEntryType::STARTING_GRANT,
                $creditPence,
                'test',
                'dorm-grant-' . bin2hex(random_bytes(5)),
            );
        }

        return $user;
    }

    private function purge(): void
    {
        $c  = $this->connection;
        $cc = self::COUNTRY;
        $u  = "email LIKE 'dorm-%@test.local'";

        $c->executeStatement("DELETE FROM club_dormancy_warning WHERE claim_id IN (SELECT pc.id FROM plot_claim pc JOIN location l ON l.id = pc.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("UPDATE club SET home_location_id = NULL WHERE home_location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM plot_claim_valuation WHERE claim_id IN (SELECT pc.id FROM plot_claim pc JOIN location l ON l.id = pc.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM plot_claim WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM plot_hold WHERE plot_id IN (SELECT p.id FROM land_plot p JOIN location l ON l.id = p.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM stadium_estimate_input WHERE estimate_id IN (SELECT e.id FROM stadium_estimate e JOIN location l ON l.id = e.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM stadium_estimate WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM inbox_message WHERE club_id IN (SELECT c.id FROM club c JOIN \"user\" u ON u.id = c.user_id WHERE u.$u)");
        $c->executeStatement("DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM \"user\" WHERE $u)");
        $c->executeStatement("DELETE FROM leaderboard_entry WHERE club_id IN (SELECT c.id FROM club c JOIN \"user\" u ON u.id = c.user_id WHERE u.$u)");
        $c->executeStatement("DELETE FROM club WHERE user_id IN (SELECT id FROM \"user\" WHERE $u)");
        $c->executeStatement("DELETE FROM \"user\" WHERE $u");
        $c->executeStatement('DELETE FROM location WHERE country_code = :x', ['x' => $cc]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :x', ['x' => $cc]);
    }
}

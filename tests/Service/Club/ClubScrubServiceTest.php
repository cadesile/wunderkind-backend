<?php

declare(strict_types=1);

namespace App\Tests\Service\Club;

use App\Entity\Club;
use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Entity\LeaderboardEntry;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\PlotClaim;
use App\Entity\MatchResult;
use App\Entity\SeasonRatingsSnapshot;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use App\Enum\Location\ClaimReleaseReason;
use App\Enum\UserLedgerEntryType;
use App\Repository\Location\PlotClaimRepository;
use App\Repository\UserLedgerRepository;
use App\Service\Club\ClubScrubService;
use App\Service\Leaderboard\LeaderboardPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Deleting one club out from under an owner who survives it.
 *
 * THE OWNER SURVIVING IS THE WHOLE DIFFERENCE from AccountDeletionServiceTest, and every
 * assertion here that is not simply "the row is gone" exists because of it: the account's
 * balance, its payment history and its land records all have to come out the far side intact
 * while everything club-scoped goes.
 */
class ClubScrubServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ClubScrubService $scrub;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em    = self::getContainer()->get(EntityManagerInterface::class);
        $this->scrub = self::getContainer()->get(ClubScrubService::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $ids = $this->em->getClassMetadata($entity::class)->getIdentifierValues($entity);

            if ($ids === []) {
                continue;
            }

            $managed = $this->em->find($entity::class, count($ids) === 1 ? reset($ids) : $ids);

            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }

        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }

    public function testClubScopedRowsGoAndTheOwnersLedgerSurvivesDetached(): void
    {
        $user = new User('scrub-' . bin2hex(random_bytes(6)) . '@example.test');
        $user->setPassword('x');
        $this->persist($user);

        $doomed = new Club('Doomed Town FC', $user);
        $doomed->setCurrentSeason(2);
        $this->persist($doomed);

        // A SECOND CLUB, deliberately. Half of what this service must get right is what it does
        // NOT touch, and an account with one club cannot show that.
        $keeper = new Club('Keeper Town FC', $user);
        $this->persist($keeper);
        $this->em->flush();

        $sync = new SyncRecord($doomed, 5, new \DateTimeImmutable(), ['w' => 5]);
        $this->persist($sync);
        $this->persist(new MatchResult($doomed, 2, 1, 3, 1));
        $this->persist(new SeasonRatingsSnapshot(1, 1, 8, (string) $doomed->getId(), 'Doomed Town FC', 50, 4));
        $this->persist(new LeaderboardEntry($doomed, LeaderboardCategory::CAREER_EARNINGS, 'all-time'));

        // Two ledger rows: one attributed to the doomed club, one to the keeper. The first is
        // the row that must be DETACHED rather than deleted — deleting a club's entries rolls
        // the owner's balance backwards, because the balance is a chain of before/after
        // snapshots. This codebase has shipped that defect once already.
        $doomedDraw = UserLedger::fromSyncLedgerEntry(
            $user,
            $doomed,
            UserLedgerEntryType::DIVIDEND_DRAW,
            50_000,
            0,
            new \DateTimeImmutable('-2 days'),
            new \DateTimeImmutable('-2 days'),
            $sync,
            0,
        );
        $this->persist($doomedDraw);
        $this->em->flush();

        $keeperDraw = UserLedger::forBackendEvent(
            $user,
            $keeper,
            UserLedgerEntryType::CUSTOM_INCOME,
            25_000,
            50_000,
            'test',
            'scrub-' . bin2hex(random_bytes(6)),
        );
        $this->persist($keeperDraw);
        $this->em->flush();

        $balanceBefore = self::getContainer()->get(UserLedgerRepository::class)->getCurrentBalance($user);
        $doomedId      = (string) $doomed->getId();
        $doomedDrawId  = $doomedDraw->getId();

        $counts = $this->scrub->scrub($doomed);

        $this->em->clear();

        // The club and everything scoped to it.
        self::assertNull($this->em->find(Club::class, $doomedId));
        self::assertSame(1, $counts['club']);
        self::assertSame(1, $counts['sync_record']);
        self::assertSame(1, $counts['match_result']);
        self::assertSame(1, $counts['leaderboard_entry']);
        self::assertSame(1, $counts['season_ratings_snapshot']);

        // The ledger row SURVIVES, detached. This is the assertion that matters most in the file.
        $survivor = $this->em->find(UserLedger::class, $doomedDrawId);
        self::assertNotNull($survivor, 'the club-scoped ledger row must not be deleted');
        self::assertNull($survivor->getClub(), 'and it must be detached from the deleted club');

        // So the owner's balance is untouched by the scrub itself.
        $reloadedUser = $this->em->find(User::class, $user->getId());
        self::assertSame(
            $balanceBefore,
            self::getContainer()->get(UserLedgerRepository::class)->getCurrentBalance($reloadedUser),
        );

        // And the other club is entirely unaffected.
        self::assertNotNull($this->em->find(Club::class, (string) $keeper->getId()));
    }

    public function testAnActivePlotClaimIsReleasedSoThePlotIsBuyableAgain(): void
    {
        // THE DEFECT THIS PINS: plot_claim.club_id is ON DELETE SET NULL, so deleting a club
        // does not block on a claim — it leaves one whose released_at is still NULL and whose
        // club_id is now NULL. Because uq_plot_claim_active_plot keys on plot_id alone, that
        // plot is then owned by nobody and can never be claimed or released again.
        $user = new User('scrub-plot-' . bin2hex(random_bytes(6)) . '@example.test');
        $user->setPassword('x');
        $this->persist($user);

        $letters = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        $code    = $letters[random_int(0, 25)] . $letters[random_int(0, 25)];

        $profile = $this->em->getRepository(CountryProfile::class)->findOneBy(['code' => $code])
            ?? $this->persist(new CountryProfile($code, 'Scrub Test ' . $code));

        $location = new Location(
            $profile,
            'Plotville',
            'plotville-' . bin2hex(random_bytes(4)),
            'plotville',
        );
        $location->setLandPence(1_000_000);
        $this->persist($location);

        $plot = new LandPlot($location, 1);
        $this->persist($plot);

        $club = new Club('Plotville FC', $user);
        $club->setHomeLocation($location);
        $this->persist($club);
        $this->em->flush();

        $claim = new PlotClaim($plot, $user, 1_000_000);
        $claim->attachClub($club);
        $this->persist($claim);
        $this->em->flush();

        $claimId = $claim->getId();

        $counts = $this->scrub->scrub($club);

        self::assertSame(1, $counts['plot_claim_released']);

        $this->em->clear();

        $reloaded = $this->em->find(PlotClaim::class, $claimId);

        self::assertNotNull($reloaded, 'the purchase record survives the club — it is an audit trail');
        self::assertFalse($reloaded->isActive(), 'and it must not be left active, or the plot is stranded');
        self::assertSame(ClaimReleaseReason::CLUB_DELETED, $reloaded->getReleaseReason());
        self::assertSame('Plotville FC', $reloaded->getClubNameAtClaim(), 'the frozen name outlives the club');

        // The real proof: nothing active is left on the plot, so it is back on the market.
        self::assertNull(
            self::getContainer()->get(PlotClaimRepository::class)->findActiveForPlot(
                $this->em->find(LandPlot::class, $plot->getId()),
            ),
        );
    }

    public function testArchiveRowsAreDeletedRatherThanRetained(): void
    {
        // DELIBERATELY THE OPPOSITE of AccountDeletionService, which nulls user_id and KEEPS the
        // row so a settled board's ranks do not skip a number. A bought-out club is meant to
        // leave no trace, so the row goes — and the cost (boards with gaps in their ranks, an
        // entry_count that no longer matches its rows) was accepted when this was specified.
        $user = new User('scrub-archive-' . bin2hex(random_bytes(6)) . '@example.test');
        $user->setPassword('x');
        $this->persist($user);

        $club = new Club('Archive Scrub FC', $user);
        $this->persist($club);
        $this->em->flush();

        $bounds = LeaderboardPeriod::boundsOf('2012-M01');
        self::assertNotNull($bounds);

        $archive = new LeaderboardPeriodArchive(
            null,
            LeaderboardCategory::CLUB_GOALS,
            AwardCadence::MONTHLY,
            'Scrub Test Award',
            '2012-M01',
            $bounds[0],
            $bounds[1],
            null,
            new \DateTimeImmutable(),
        );
        $this->persist($archive);

        $entry = new LeaderboardPeriodArchiveEntry(
            $archive,
            1,
            (string) $club->getId(),
            $club->getName(),
            (string) $user->getId(),
            120,
            620,
            500,
            false,
            true,
            'Scrubbed Striker',
            ['kit' => 'stripes'],
            null,
            ['badgeShape' => 'shield'],
            'EN',
        );
        $this->persist($entry);
        $this->em->flush();

        $entryId = $entry->getId();

        $counts = $this->scrub->scrub($club);

        self::assertSame(1, $counts['leaderboard_period_archive_entry']);

        $this->em->clear();

        self::assertNull($this->em->find(LeaderboardPeriodArchiveEntry::class, $entryId));
    }
}

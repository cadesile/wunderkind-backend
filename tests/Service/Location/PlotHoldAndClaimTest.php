<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Club;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\PlotHold;
use App\Entity\User;
use App\Enum\CitySize;
use App\Enum\Location\ClaimReleaseReason;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\UserLedgerEntryType;
use App\Exception\Location\PlotUnavailableException;
use App\Repository\Location\PlotClaimRepository;
use App\Repository\Location\PlotHoldRepository;
use App\Repository\UserLedgerRepository;
use App\Service\Location\LandPlotProvisioner;
use App\Service\Location\PlotClaimService;
use App\Service\Location\PlotHoldService;
use App\Service\Location\StadiumEstimateService;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Holds and claims: the races, the frozen quote, and the money.
 *
 * Everything here is against the real database, because every guarantee in this feature is
 * enforced by a PostgreSQL constraint rather than by application logic. Testing it with mocks
 * would test the mocks.
 */
class PlotHoldAndClaimTest extends KernelTestCase
{
    private const COUNTRY = 'ZP';

    private EntityManagerInterface $em;
    private Connection $connection;
    private PlotHoldService $holds;
    private PlotClaimService $claims;
    private PlotHoldRepository $holdRepository;
    private PlotClaimRepository $claimRepository;
    private UserLedgerRepository $ledgerRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $c = self::getContainer();

        $this->em               = $c->get(EntityManagerInterface::class);
        $this->connection       = $c->get(Connection::class);
        $this->holds            = $c->get(PlotHoldService::class);
        $this->claims           = $c->get(PlotClaimService::class);
        $this->holdRepository   = $c->get(PlotHoldRepository::class);
        $this->claimRepository  = $c->get(PlotClaimRepository::class);
        $this->ledgerRepository = $c->get(UserLedgerRepository::class);

        $this->purge();

        $country = new CountryProfile(self::COUNTRY, 'Plotland');
        // LIVE, because PlotHoldService refuses a country that is not open to players.
        $country->setStatus(CountryProfileStatus::LIVE);
        $this->em->persist($country);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $c = $this->connection;

        $c->executeStatement("DELETE FROM plot_claim_valuation WHERE claim_id IN (SELECT pc.id FROM plot_claim pc JOIN location l ON l.id = pc.location_id WHERE l.country_code = :x)", ['x' => self::COUNTRY]);
        $c->executeStatement("DELETE FROM plot_claim WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => self::COUNTRY]);
        $c->executeStatement("DELETE FROM plot_hold WHERE plot_id IN (SELECT p.id FROM land_plot p JOIN location l ON l.id = p.location_id WHERE l.country_code = :x)", ['x' => self::COUNTRY]);
        $c->executeStatement("DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM \"user\" WHERE email LIKE 'plot-%@test.local')");
        $c->executeStatement("DELETE FROM stadium_estimate_input WHERE estimate_id IN (SELECT e.id FROM stadium_estimate e JOIN location l ON l.id = e.location_id WHERE l.country_code = :x)", ['x' => self::COUNTRY]);
        $c->executeStatement("DELETE FROM stadium_estimate WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => self::COUNTRY]);
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => self::COUNTRY]);
        $c->executeStatement('DELETE FROM club WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE \'plot-%@test.local\')');
        $c->executeStatement('DELETE FROM "user" WHERE email LIKE \'plot-%@test.local\'');
        $c->executeStatement('DELETE FROM location WHERE country_code = :x', ['x' => self::COUNTRY]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :x', ['x' => self::COUNTRY]);
    }

    private function plot(string $town = 'Plotville', int $population = 200_000, ?int $landPence = 5_000_000_00): LandPlot
    {
        $country  = $this->em->find(CountryProfile::class, self::COUNTRY);
        $location = new Location($country, $town, strtolower(self::COUNTRY . '-' . $town), strtolower($town));
        $location->setRegion('R');
        $location->setPopulation($population);
        $location->setCoordinates(53.0, -2.0);
        $location->setLandPence($landPence);
        $location->setLandValuedAt(new \DateTimeImmutable());

        $this->em->persist($location);

        $plot = new LandPlot($location, 1);
        $this->em->persist($plot);
        $this->em->flush();

        return $plot;
    }

    /** A funded account — real-world prices mean a player needs real-world money. */
    private function user(string $suffix, int $creditPence = 0): User
    {
        $user = new User("plot-{$suffix}-" . bin2hex(random_bytes(4)) . '@test.local');
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
                'grant-' . $suffix . '-' . bin2hex(random_bytes(3)),
            );
        }

        return $user;
    }

    // ───────────────────────── the hold races ─────────────────────────

    /**
     * Two players, one plot, one winner — decided by the database.
     *
     * The main race in the feature. acquire() deliberately does not check first: both callers
     * attempt the insert and the partial unique lets exactly one through. A check-then-insert
     * would let both pass.
     */
    public function testOnlyOnePlayerCanHoldAPlot(): void
    {
        $plot = $this->plot();
        $a    = $this->user('a');
        $b    = $this->user('b');

        $hold = $this->holds->acquire($a, $plot);

        self::assertTrue($hold->isActive());
        self::assertSame(PlotHold::HOLD_MINUTES * 60, $hold->secondsRemaining($hold->getCreatedAt()));

        try {
            $this->holds->acquire($b, $plot);
            self::fail('the second player should not have got a hold');
        } catch (PlotUnavailableException $e) {
            self::assertSame(PlotUnavailableException::PLOT_HELD, $e->reason);
        }
    }

    /** One hold per account, so nobody reserves the map. */
    public function testOnePlayerCannotHoldTwoPlots(): void
    {
        $first  = $this->plot('Firstown');
        $second = $this->plot('Secondton');
        $user   = $this->user('greedy');

        $this->holds->acquire($user, $first);

        try {
            $this->holds->acquire($user, $second);
            self::fail('a second simultaneous hold should be refused');
        } catch (PlotUnavailableException $e) {
            self::assertSame(PlotUnavailableException::HOLD_LIMIT, $e->reason);
            // The message names the town they are already holding, which is the actionable bit.
            self::assertStringContainsString('Firstown', $e->getMessage());
        }
    }

    /** Releasing frees the plot immediately — the partial predicate doing its job. */
    public function testReleasingAHoldFreesThePlot(): void
    {
        $plot = $this->plot();
        $a    = $this->user('a');
        $b    = $this->user('b');

        $hold = $this->holds->acquire($a, $plot);

        self::assertTrue($this->holds->release($a, (string) $hold->getId()));

        $this->em->clear();

        // Now someone else can take it.
        $second = $this->holds->acquire(
            $this->em->find(User::class, (string) $b->getId()),
            $this->em->find(LandPlot::class, (string) $plot->getId()),
        );

        self::assertTrue($second->isActive());
    }

    // ──────────────── surplus plots on a demoted town ────────────────

    public function testALiveHoldProtectsAPlotFromTheReap(): void
    {
        // A hold is a player at the checkout with a price quoted. Retiring the plot underneath
        // them would fail the purchase for a reason they could not have seen coming.
        //
        // The order is the real-world one: the plot was legitimate when the hold was taken, and
        // the town was demoted afterwards.
        [$first, $surplus] = $this->twoPlotBigTown();

        $this->holds->acquire($this->user('holder'), $surplus);
        $this->demoteToSmall($first);

        self::getContainer()->get(LandPlotProvisioner::class)
            ->reapSurplus([(string) $first->getLocation()->getId()]);

        self::assertSame('active', $this->statusOf($surplus), 'held, so untouchable');
    }

    public function testAHoldThatLapsesLeavesNoSurplusPlotBehind(): void
    {
        // The gap this closes. Nothing runs when a hold expires, so the plot it was protecting
        // would stay active above the town's target with no event to collect it — and the next
        // player could buy land the town is no longer big enough for.
        [$first, $surplus] = $this->twoPlotBigTown();

        $hold = $this->holds->acquire($this->user('lapser'), $surplus);
        $this->demoteToSmall($first);

        self::getContainer()->get(LandPlotProvisioner::class)
            ->reapSurplus([(string) $first->getLocation()->getId()]);
        self::assertSame('active', $this->statusOf($surplus), 'protected while held');

        // The hold lapses, and nothing runs.
        $this->connection->executeStatement(
            'UPDATE plot_hold SET expires_at = :past WHERE id = :id',
            ['past' => new \DateTimeImmutable('-1 minute'), 'id' => (string) $hold->getId()],
            ['past' => 'datetime_immutable'],
        );
        $this->em->clear();

        // The next player to reach for the town is what collects it.
        $this->holds->acquire(
            $this->user('next'),
            $this->em->find(LandPlot::class, (string) $first->getId()),
        );

        self::assertSame('retired', $this->statusOf($surplus), 'surplus, and nobody holds it now');
        self::assertSame('active', $this->statusOf($first), 'the one the town is entitled to');
    }

    public function testAPlotWhoseHoldLapsedCannotBeHeldAgainIfItIsSurplus(): void
    {
        // The refusal a player would see: reaching for the lapsed plot is what retires it, and
        // the existing not-sellable answer explains why without a new case to learn.
        [$first, $surplus] = $this->twoPlotBigTown();

        $hold = $this->holds->acquire($this->user('first'), $surplus);
        $this->demoteToSmall($first);

        $this->connection->executeStatement(
            'UPDATE plot_hold SET expires_at = :past WHERE id = :id',
            ['past' => new \DateTimeImmutable('-1 minute'), 'id' => (string) $hold->getId()],
            ['past' => 'datetime_immutable'],
        );
        $this->em->clear();

        $this->expectException(PlotUnavailableException::class);

        $this->holds->acquire(
            $this->user('unlucky'),
            $this->em->find(LandPlot::class, (string) $surplus->getId()),
        );
    }

    public function testASurplusPlotNobodyEverHeldIsLeftAlone(): void
    {
        // The guarantee the narrowing protects. A town can be over target because an operator
        // lowered plotsByCitySize, or because somebody added a plot by hand — neither is a fault
        // to be corrected the moment a player looks at the town.
        [$first, $extra] = $this->twoPlotBigTown();
        $this->demoteToSmall($first);
        $this->em->clear();

        $this->holds->acquire(
            $this->user('ordinary'),
            $this->em->find(LandPlot::class, (string) $extra->getId()),
        );

        self::assertSame('active', $this->statusOf($extra), 'never held, so never collected here');
    }

    public function testReleasingAHoldOnADemotedTownRetiresTheSurplus(): void
    {
        [$first, $surplus] = $this->twoPlotBigTown();

        $user = $this->user('quitter');
        $hold = $this->holds->acquire($user, $surplus);
        $this->demoteToSmall($first);

        self::assertTrue($this->holds->release($user, (string) $hold->getId()));
        self::assertSame('retired', $this->statusOf($surplus), 'not back on the market');
        self::assertSame('active', $this->statusOf($first));
    }

    /**
     * A BIG town with two plots, as it would have been before a demotion.
     *
     * BIG rather than the entity default of SMALL on purpose: at SMALL the second plot is
     * surplus from the moment it exists, and acquire() would reap it before a hold could be
     * taken — which is the right behaviour but the wrong starting position for these.
     *
     * @return array{LandPlot, LandPlot}
     */
    private function twoPlotBigTown(): array
    {
        $first = $this->plot();
        $first->getLocation()->setCitySize(CitySize::BIG);

        $second = new LandPlot($first->getLocation(), 2);
        $this->em->persist($second);
        $this->em->flush();

        return [$first, $second];
    }

    /** SMALL means a target of one plot, so anything past plot 1 is surplus. */
    private function demoteToSmall(LandPlot $plot): void
    {
        $plot->getLocation()->setCitySize(CitySize::SMALL);
        $this->em->flush();
    }

    private function statusOf(LandPlot $plot): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT status FROM land_plot WHERE id = :id',
            ['id' => (string) $plot->getId()],
        );
    }

    /**
     * An EXPIRED hold blocks nobody, with no cleanup job involved.
     *
     * The mechanism the brief asked for. The unique predicate cannot mention time — a predicate
     * must be IMMUTABLE — so an expired row still sits in the index; acquire() releases exactly
     * the blocking rows first, which is what makes the index agree with the read semantics.
     */
    public function testAnExpiredHoldDoesNotBlockANewOne(): void
    {
        $plot = $this->plot();
        $a    = $this->user('a');
        $b    = $this->user('b');

        $hold = $this->holds->acquire($a, $plot);

        // Backdate it past expiry, exactly as the clock would.
        $this->connection->executeStatement(
            'UPDATE plot_hold SET expires_at = :past WHERE id = :id',
            ['past' => new \DateTimeImmutable('-1 minute'), 'id' => (string) $hold->getId()],
            ['past' => 'datetime_immutable'],
        );

        $this->em->clear();

        // Invisible to reads...
        self::assertNull($this->holdRepository->findActiveForPlot(
            $this->em->find(LandPlot::class, (string) $plot->getId()),
        ));

        // ...and no obstacle to the next player.
        $second = $this->holds->acquire(
            $this->em->find(User::class, (string) $b->getId()),
            $this->em->find(LandPlot::class, (string) $plot->getId()),
        );

        self::assertTrue($second->isActive());
    }

    /** And an expired hold of your own does not count against your one-hold limit. */
    public function testAnExpiredHoldDoesNotCountAgainstTheUserLimit(): void
    {
        $first  = $this->plot('Firstown');
        $second = $this->plot('Secondton');
        $user   = $this->user('patient');

        $hold = $this->holds->acquire($user, $first);

        $this->connection->executeStatement(
            'UPDATE plot_hold SET expires_at = :past WHERE id = :id',
            ['past' => new \DateTimeImmutable('-1 minute'), 'id' => (string) $hold->getId()],
            ['past' => 'datetime_immutable'],
        );

        $this->em->clear();

        $next = $this->holds->acquire(
            $this->em->find(User::class, (string) $user->getId()),
            $this->em->find(LandPlot::class, (string) $second->getId()),
        );

        self::assertTrue($next->isActive());
    }

    /** A plot that cannot be priced cannot be held. */
    public function testAnUnpriceablePlotCannotBeHeld(): void
    {
        $plot = $this->plot('Unvalued', 200_000, null);

        $this->expectException(PlotUnavailableException::class);

        $this->holds->acquire($this->user('a'), $plot);
    }

    /** A country that is not live is not open for business. */
    public function testADraftCountrysPlotsCannotBeHeld(): void
    {
        $plot    = $this->plot();
        $country = $this->em->find(CountryProfile::class, self::COUNTRY);
        $country->setStatus(CountryProfileStatus::DRAFT);
        $this->em->flush();

        try {
            $this->holds->acquire($this->user('a'), $plot);
            self::fail('a draft country should not sell plots');
        } catch (PlotUnavailableException $e) {
            self::assertSame(PlotUnavailableException::NOT_SELLABLE, $e->reason);
        }
    }

    // ───────────────────────── the frozen quote ─────────────────────────

    /**
     * THE PROMISE THE WHOLE FEATURE RESTS ON: the price cannot move while you decide.
     *
     * The land is revalued and the estimate rebuilt mid-hold, and the hold still charges what it
     * quoted. Note what makes this work — the estimate row is SUPERSEDED rather than deleted,
     * because plot_hold.stadium_estimate_id is ON DELETE RESTRICT.
     */
    public function testAHoldsQuoteSurvivesARevaluation(): void
    {
        $plot = $this->plot('Steady', 200_000, 5_000_000_00);
        $user = $this->user('a', 50_000_000_00);

        $hold        = $this->holds->acquire($user, $plot);
        $quotedLand  = $hold->getLandPence();
        $quotedGround = $hold->getStadiumPence();

        self::assertSame(5_000_000_00, $quotedLand);

        // Revalue the land upwards and rebuild the estimate, as an operator might mid-session.
        $location = $plot->getLocation();
        $location->setLandPence(99_000_000_00);
        $location->setLandValuedAt(new \DateTimeImmutable('+1 second'));
        $this->em->flush();

        self::assertNotNull(self::getContainer()->get(StadiumEstimateService::class)->refresh($location));

        $this->em->clear();

        $claim = $this->claims->claimFromHold(
            $this->em->find(User::class, (string) $user->getId()),
            (string) $hold->getId(),
        );

        self::assertSame($quotedLand, $claim->getLandPencePaid(), 'the player must pay the price they were quoted');
        self::assertNotSame(99_000_000_00, $claim->getLandPencePaid());
        self::assertGreaterThan(0, $quotedGround);
    }

    /** The old estimate row cannot be deleted while a hold points at it. */
    public function testASupersededEstimateUnderAHoldCannotBeDeleted(): void
    {
        $plot = $this->plot();
        $user = $this->user('a', 50_000_000_00);

        $hold = $this->holds->acquire($user, $plot);

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);

        $this->connection->executeStatement(
            'DELETE FROM stadium_estimate WHERE id = :id',
            ['id' => (string) $hold->getStadiumEstimate()->getId()],
        );
    }

    // ───────────────────────── claiming ─────────────────────────

    public function testClaimingChargesAccountCreditAndRecordsTheClaim(): void
    {
        $plot = $this->plot('Buyable', 200_000, 5_000_000_00);
        $user = $this->user('buyer', 50_000_000_00);

        $hold  = $this->holds->acquire($user, $plot);
        $claim = $this->claims->claimFromHold($user, (string) $hold->getId());

        self::assertTrue($claim->isActive());
        self::assertSame(5_000_000_00, $claim->getLandPencePaid());
        self::assertSame((string) $user->getId(), (string) $claim->getUser()->getId());
        // No club yet — bought before founding one, which the brief's flow allows.
        self::assertNull($claim->getClub());

        // Charged to ACCOUNT credit, never club.balance.
        self::assertSame(
            50_000_000_00 - 5_000_000_00,
            $this->ledgerRepository->getCurrentBalance($user),
        );
        self::assertNotNull($claim->getLandLedgerEntry());
        self::assertSame(UserLedgerEntryType::LAND_PURCHASE, $claim->getLandLedgerEntry()->getType());

        // And the hold is spent.
        $this->em->clear();
        self::assertNull($this->holdRepository->findActiveForUser($this->em->find(User::class, (string) $user->getId())));
    }

    /** Without enough credit the purchase is refused, and nothing is charged. */
    public function testClaimingIsRefusedWithoutEnoughCredit(): void
    {
        $plot = $this->plot('Pricey', 200_000, 80_000_000_00);
        $user = $this->user('poor', 1_000_00);

        $hold = $this->holds->acquire($user, $plot);

        try {
            $this->claims->claimFromHold($user, (string) $hold->getId());
            self::fail('an unaffordable plot should be refused');
        } catch (PlotUnavailableException $e) {
            self::assertSame(PlotUnavailableException::INSUFFICIENT, $e->reason);
            self::assertSame(1_000_00, $e->context['balancePence']);
            self::assertSame(80_000_000_00, $e->context['requiredPence']);
        }

        $this->em->clear();

        self::assertSame(1_000_00, $this->ledgerRepository->getCurrentBalance(
            $this->em->find(User::class, (string) $user->getId()),
        ));
        self::assertNull($this->claimRepository->findActiveForPlot(
            $this->em->find(LandPlot::class, (string) $plot->getId()),
        ));
    }

    /** A hold can be spent once. A double-tapped CLAIM buys one plot, not two. */
    public function testAHoldCannotBeClaimedTwice(): void
    {
        $plot = $this->plot();
        $user = $this->user('a', 50_000_000_00);

        $hold = $this->holds->acquire($user, $plot);

        $this->claims->claimFromHold($user, (string) $hold->getId());

        $balanceAfterOne = $this->ledgerRepository->getCurrentBalance($user);

        try {
            $this->claims->claimFromHold($user, (string) $hold->getId());
            self::fail('a consumed hold should not claim again');
        } catch (PlotUnavailableException $e) {
            self::assertSame(PlotUnavailableException::HOLD_CONSUMED, $e->reason);
        }

        self::assertSame($balanceAfterOne, $this->ledgerRepository->getCurrentBalance($user), 'no double charge');
    }

    /** Someone else's hold is not a way to buy a plot. */
    public function testAnotherPlayersHoldCannotBeClaimed(): void
    {
        $plot = $this->plot();
        $a    = $this->user('a');
        $b    = $this->user('b', 50_000_000_00);

        $hold = $this->holds->acquire($a, $plot);

        try {
            $this->claims->claimFromHold($b, (string) $hold->getId());
            self::fail("another player's hold should not be claimable");
        } catch (PlotUnavailableException $e) {
            self::assertSame(PlotUnavailableException::HOLD_NOT_YOURS, $e->reason);
        }
    }

    /** An expired hold cannot be cashed in. */
    public function testAnExpiredHoldCannotBeClaimed(): void
    {
        $plot = $this->plot();
        $user = $this->user('slow', 50_000_000_00);

        $hold = $this->holds->acquire($user, $plot);

        $this->connection->executeStatement(
            'UPDATE plot_hold SET expires_at = :past WHERE id = :id',
            ['past' => new \DateTimeImmutable('-1 second'), 'id' => (string) $hold->getId()],
            ['past' => 'datetime_immutable'],
        );

        $this->expectException(PlotUnavailableException::class);

        $this->claims->claimFromHold($user, (string) $hold->getId());
    }

    /** One live claim per plot, enforced by the database. */
    public function testAClaimedPlotCannotBeHeldAgain(): void
    {
        $plot = $this->plot();
        $a    = $this->user('a', 50_000_000_00);
        $b    = $this->user('b', 50_000_000_00);

        $hold = $this->holds->acquire($a, $plot);
        $this->claims->claimFromHold($a, (string) $hold->getId());

        $this->em->clear();

        try {
            $this->holds->acquire(
                $this->em->find(User::class, (string) $b->getId()),
                $this->em->find(LandPlot::class, (string) $plot->getId()),
            );
            self::fail('a claimed plot should not be holdable');
        } catch (PlotUnavailableException $e) {
            self::assertSame(PlotUnavailableException::PLOT_CLAIMED, $e->reason);
        }
    }

    // ───────────────────────── club attachment & stadium ─────────────────────────

    /** Attaching a club freezes its name, so `claimedBy` survives the club's deletion. */
    public function testAttachedClubNameIsFrozenAndSurvivesDeletion(): void
    {
        $plot = $this->plot();
        $user = $this->user('owner', 50_000_000_00);

        $hold  = $this->holds->acquire($user, $plot);
        $claim = $this->claims->claimFromHold($user, (string) $hold->getId());

        $club = new Club('Plotville Rangers', $user);
        $this->em->persist($club);
        $this->em->flush();

        $this->claims->attachClub($claim, $club);

        self::assertSame('Plotville Rangers', $claim->getClaimedByName());

        $plotId  = (string) $plot->getId();
        $claimId = (string) $claim->getId();

        // Release the claim first — otherwise a nulled-but-active claim would leave the plot
        // owned by nobody, which is exactly why the delete path must release.
        $this->claimRepository->releaseActiveForClubs([(string) $club->getId()], new \DateTimeImmutable());
        $this->connection->executeStatement('DELETE FROM club WHERE id = :id', ['id' => (string) $club->getId()]);

        $this->em->clear();

        $reloaded = $this->claimRepository->find($claimId);

        self::assertNotNull($reloaded, 'the purchase record must outlive the club');
        self::assertNull($reloaded->getClub(), 'the club FK should be nulled');
        self::assertSame('Plotville Rangers', $reloaded->getClaimedByName(), 'the frozen name should still render');
        self::assertSame(ClaimReleaseReason::CLUB_DELETED, $reloaded->getReleaseReason());
        self::assertSame($plotId, (string) $reloaded->getPlot()->getId());
    }

    /** Building the ground charges the quoted figure, under the same source ref as the land. */
    public function testBuildingAStadiumChargesTheQuotedPrice(): void
    {
        $plot = $this->plot('Grounded', 200_000, 5_000_000_00);
        $user = $this->user('builder', 200_000_000_00);

        $hold  = $this->holds->acquire($user, $plot);
        $quote = $hold->getStadiumPence();
        $claim = $this->claims->claimFromHold($user, (string) $hold->getId());

        $balanceBefore = $this->ledgerRepository->getCurrentBalance($user);

        $this->claims->buildStadium($claim);

        self::assertTrue($claim->hasStadium());
        self::assertSame($quote, $claim->getStadiumPencePaid(), 'the quoted ground price should be charged');
        self::assertSame($balanceBefore - $quote, $this->ledgerRepository->getCurrentBalance($user));
        self::assertNotNull($claim->getStadiumLedgerEntry());
        self::assertSame($claim->getLandPencePaid() + $quote, $claim->getTotalPaidPence());
    }

    /** Building twice is a no-op, not a double charge. */
    public function testBuildingAStadiumTwiceDoesNotChargeTwice(): void
    {
        $plot = $this->plot('Once', 200_000, 5_000_000_00);
        $user = $this->user('builder', 200_000_000_00);

        $hold  = $this->holds->acquire($user, $plot);
        $claim = $this->claims->claimFromHold($user, (string) $hold->getId());

        $this->claims->buildStadium($claim);
        $after = $this->ledgerRepository->getCurrentBalance($user);

        $this->claims->buildStadium($claim);

        self::assertSame($after, $this->ledgerRepository->getCurrentBalance($user));
    }

    /** `claimedBy` is readable in bulk without hydrating a claim — the list endpoint's path. */
    public function testClaimedByNamesAreProjectedInBulk(): void
    {
        $plot = $this->plot();
        $user = $this->user('owner', 50_000_000_00);

        $hold  = $this->holds->acquire($user, $plot);
        $claim = $this->claims->claimFromHold($user, (string) $hold->getId());

        $club = new Club('Bulk FC', $user);
        $this->em->persist($club);
        $this->em->flush();
        $this->claims->attachClub($claim, $club);

        $names = $this->claimRepository->findClaimedByNames([(string) $plot->getId()]);

        self::assertSame(['Bulk FC'], array_values($names));
    }
}

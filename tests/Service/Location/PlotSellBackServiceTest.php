<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Club;
use App\Entity\ClubFacility;
use App\Entity\FacilityTemplate;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\PlotClaim;
use App\Entity\Location\PlotClaimValuation;
use App\Entity\User;
use App\Enum\Location\ClaimReleaseReason;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\UserLedgerEntryType;
use App\Repository\Location\PlotClaimValuationRepository;
use App\Repository\UserLedgerRepository;
use App\Service\Location\ClubValuationService;
use App\Service\Location\PlotClaimService;
use App\Service\Location\PlotHoldService;
use App\Service\Location\PlotSellBackService;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Selling a plot back, and the club valuation it depends on.
 *
 * THE ANTI-EXPLOIT ASSERTION IS THE ONE THAT MATTERS: a voluntary sell-back and a dormancy
 * reclaim must pay the SAME figure on identical state. If dormancy ever paid more, the optimal
 * play would be to stop logging in, which is the exact opposite of what a dormancy rule is for.
 */
class PlotSellBackServiceTest extends KernelTestCase
{
    private const COUNTRY = 'ZS';

    private EntityManagerInterface $em;
    private Connection $connection;
    private PlotSellBackService $sellBack;
    private ClubValuationService $clubValuation;
    private UserLedgerRepository $ledgerRepository;
    private PlotClaimValuationRepository $valuations;

    protected function setUp(): void
    {
        self::bootKernel();

        $c = self::getContainer();

        $this->em               = $c->get(EntityManagerInterface::class);
        $this->connection       = $c->get(Connection::class);
        $this->sellBack         = $c->get(PlotSellBackService::class);
        $this->clubValuation    = $c->get(ClubValuationService::class);
        $this->ledgerRepository = $c->get(UserLedgerRepository::class);
        $this->valuations       = $c->get(PlotClaimValuationRepository::class);

        $this->purge();

        $country = new CountryProfile(self::COUNTRY, 'Sellland');
        $country->setStatus(CountryProfileStatus::LIVE);
        $this->em->persist($country);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    // ───────────────────────── the club valuation ─────────────────────────

    public function testAClubWithNoFacilitiesIsWorthItsBalance(): void
    {
        $club = $this->club(balancePence: 5_000_00);

        $this->assertSame(5_000_00, $this->clubValuation->valuePence($club));
    }

    public function testFacilityInvestmentUsesTheDocumentedCostCurve(): void
    {
        $club = $this->club(balancePence: 0);

        // FacilityTemplate::$baseCost documents "(currentLevel + 1) x baseCost" per upgrade, so
        // reaching level 3 costs baseCost x (1 + 2 + 3) = baseCost x 6.
        $this->facility($club, 'test_stand', level: 3, baseCost: 1_000);

        $this->assertSame(6_000, $this->clubValuation->valuePence($club));
    }

    public function testTheSquadIsNotCounted(): void
    {
        $club = $this->club(balancePence: 1_000);

        // Guards against someone later summing Player::$contractValue as an asset. It is a
        // WAGE — every writer sets it from $baseWage * $multiplier — so counting it would be
        // valuing wages as capital.
        $breakdown = $this->clubValuation->breakdown($club);

        $this->assertSame(1_000, $breakdown['pence']);
        $this->assertArrayNotHasKey('squadPence', $breakdown);
        $this->assertNotEmpty($breakdown['excluded'], 'the exclusions are recorded, not silent');
    }

    public function testANegativeBalanceNeverProducesANegativeValuation(): void
    {
        $club = $this->club(balancePence: -50_000);

        // club.balance is device-reported and can go negative if the client overspent. Paying a
        // negative valuation would DEBIT a player for selling their own plot.
        $this->assertSame(0, $this->clubValuation->valuePence($club));
    }

    // ───────────────────────── the quote ─────────────────────────

    public function testTheQuotePaysNinetyPercentOfLandAndHalfTheStadium(): void
    {
        [$claim] = $this->ownedPlot(landPence: 1_000_000_00);

        $quote = $this->sellBack->quote($claim);

        $this->assertSame(900_000_00, $quote['land']['component']);
        $this->assertSame(0, $quote['stadium']['component'], 'no stadium was built');
        $this->assertSame(900_000_00, $quote['payoutPence']);
    }

    public function testALandRevaluationAfterPurchaseChangesTheQuote(): void
    {
        [$claim, $location] = $this->ownedPlot(landPence: 1_000_000_00);

        $paid = $claim->getLandPencePaid();

        // The town doubles in value after the purchase.
        $location->setLandPence(2_000_000_00);
        $location->setLandValuedAt(new \DateTimeImmutable());
        $this->em->flush();

        $quote = $this->sellBack->quote($claim);

        // Reads the CURRENT valuation, not land_pence_paid — which is the whole reason both
        // figures are kept on the claim.
        $this->assertSame(1_800_000_00, $quote['land']['component']);
        $this->assertSame($paid, $quote['land']['landPencePaid'], 'what was paid is still on record');
        $this->assertSame('current_valuation', $quote['land']['basis']);
    }

    public function testAnUnpricedLocationFallsBackToWhatWasPaid(): void
    {
        [$claim, $location] = $this->ownedPlot(landPence: 400_000_00);

        $paid = $claim->getLandPencePaid();

        // The valuation is withdrawn — an enrichment correction, say. The player must not be
        // paid nothing because our own data went missing.
        $location->setLandPence(null);
        $this->em->flush();

        $quote = $this->sellBack->quote($claim);

        $this->assertSame((int) round($paid * 0.9), $quote['land']['component']);
        $this->assertSame('paid_fallback_no_current_valuation', $quote['land']['basis']);
    }

    public function testTheClubValueIsIncludedWhenAClubIsAttached(): void
    {
        [$claim] = $this->ownedPlot(landPence: 100_000_00);

        $club = $this->club(balancePence: 7_000_00);
        self::getContainer()->get(PlotClaimService::class)->attachClub($claim, $club);

        $quote = $this->sellBack->quote($claim);

        // Releasing the plot liquidates the club, so paying only for the land would confiscate
        // everything built on it.
        $this->assertSame(7_000_00, $quote['club']['component']);
        $this->assertSame(90_000_00 + 7_000_00, $quote['payoutPence']);
    }

    public function testTheQuoteEndpointWritesNothing(): void
    {
        [$claim] = $this->ownedPlot(landPence: 100_000_00);

        $this->sellBack->quote($claim);
        $this->em->clear();

        $reloaded = $this->em->find(PlotClaim::class, $claim->getId());

        $this->assertTrue($reloaded->isActive(), 'a quote must not release the plot');
        $this->assertNull($this->valuations->findForClaim($reloaded), 'and must not store a valuation');
    }

    // ───────────────────────── executing a sell-back ─────────────────────────

    public function testSellingBackReleasesThePlotAndCreditsTheAccount(): void
    {
        [$claim, , $user] = $this->ownedPlot(landPence: 100_000_00);

        $balanceBefore = $this->ledgerRepository->getCurrentBalance($user);
        $quote         = $this->sellBack->quote($claim);

        $entry = $this->sellBack->execute($claim, ClaimReleaseReason::SOLD_BACK);

        $this->assertNotNull($entry);
        $this->assertSame(UserLedgerEntryType::SELL_BACK_CREDIT, $entry->getType());
        $this->assertSame($quote['payoutPence'], $entry->getAmountPence());
        $this->assertSame(
            $balanceBefore + $quote['payoutPence'],
            $this->ledgerRepository->getCurrentBalance($user),
        );

        $this->em->clear();
        $reloaded = $this->em->find(PlotClaim::class, $claim->getId());

        $this->assertFalse($reloaded->isActive());
        $this->assertSame(ClaimReleaseReason::SOLD_BACK, $reloaded->getReleaseReason());
        $this->assertSame($quote['payoutPence'], $reloaded->getSellBackPence());
    }

    public function testTheCreditIsAccountLevelNotAttributedToAClub(): void
    {
        [$claim] = $this->ownedPlot(landPence: 100_000_00);

        $club = $this->club(balancePence: 1_000);
        self::getContainer()->get(PlotClaimService::class)->attachClub($claim, $club);

        $entry = $this->sellBack->execute($claim, ClaimReleaseReason::SOLD_BACK);

        // The club is being liquidated; the credit is a fact about the ACCOUNT and must outlive
        // it. Attributing it to the club would also feed it into club-earnings aggregates.
        $this->assertNull($entry->getClub());
    }

    public function testSellingBackClearsTheClubsHomeLocation(): void
    {
        [$claim, $location] = $this->ownedPlot(landPence: 100_000_00);

        $club = $this->club(balancePence: 1_000);
        $club->setHomeLocation($location);
        self::getContainer()->get(PlotClaimService::class)->attachClub($claim, $club);
        $this->em->flush();

        $clubId = $club->getId();
        $this->sellBack->execute($claim, ClaimReleaseReason::SOLD_BACK);

        $this->em->clear();

        // Leaving it set would keep uq_club_name_per_location reserving a name at a town that
        // is back on the market.
        $this->assertNull($this->em->find(Club::class, $clubId)->getHomeLocation());
    }

    public function testTheWorkingIsStoredSoAFigureStaysReproducible(): void
    {
        [$claim] = $this->ownedPlot(landPence: 100_000_00);

        $this->sellBack->execute($claim, ClaimReleaseReason::SOLD_BACK);
        $this->em->clear();

        $valuation = $this->valuations->findForClaim($this->em->find(PlotClaim::class, $claim->getId()));

        $this->assertNotNull($valuation);
        $this->assertSame(PlotSellBackService::MODEL_VERSION, $valuation->getModelVersion());
        $this->assertSame(90_000_00, $valuation->getWorkingJson()['land']['component']);
    }

    public function testASecondSellBackOfTheSameClaimDoesNothingAndKeepsTheEmOpen(): void
    {
        [$claim, , $user] = $this->ownedPlot(landPence: 100_000_00);

        $this->sellBack->execute($claim, ClaimReleaseReason::SOLD_BACK);
        $balanceAfterFirst = $this->ledgerRepository->getCurrentBalance($user);

        $second = $this->sellBack->execute($claim, ClaimReleaseReason::SOLD_BACK);

        $this->assertNull($second, 'the conditional UPDATE is the lock');
        $this->assertSame($balanceAfterFirst, $this->ledgerRepository->getCurrentBalance($user), 'paid once');
        $this->assertTrue($this->em->isOpen(), 'the shared EntityManager must survive the race');
    }

    // ───────────────────────── the anti-exploit guarantee ─────────────────────────

    public function testDormancyPaysExactlyWhatAVoluntarySaleWould(): void
    {
        [$claimA] = $this->ownedPlot(landPence: 500_000_00, town: 'Alpha');
        [$claimB] = $this->ownedPlot(landPence: 500_000_00, town: 'Beta');

        $clubA = $this->club(balancePence: 12_345);
        $clubB = $this->club(balancePence: 12_345);

        $claims = self::getContainer()->get(PlotClaimService::class);
        $claims->attachClub($claimA, $clubA);
        $claims->attachClub($claimB, $clubB);

        $sold     = $this->sellBack->execute($claimA, ClaimReleaseReason::SOLD_BACK);
        $reclaimed = $this->sellBack->execute($claimB, ClaimReleaseReason::DORMANCY);

        // THE WHOLE POINT. If these ever diverge, going dormant becomes a strategy.
        $this->assertSame($sold->getAmountPence(), $reclaimed->getAmountPence());
    }

    public function testAReleaseReasonThatDoesNotPayOutCreditsNothing(): void
    {
        [$claim, , $user] = $this->ownedPlot(landPence: 100_000_00);

        $before = $this->ledgerRepository->getCurrentBalance($user);
        $entry  = $this->sellBack->execute($claim, ClaimReleaseReason::SUPPORT_REVERSAL);

        $this->assertNull($entry);
        $this->assertSame($before, $this->ledgerRepository->getCurrentBalance($user));

        $this->em->clear();
        $this->assertFalse($this->em->find(PlotClaim::class, $claim->getId())->isActive(), 'still released');
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @return array{0: PlotClaim, 1: Location, 2: User} */
    private function ownedPlot(int $landPence, string $town = 'Sellville'): array
    {
        $profile  = $this->em->find(CountryProfile::class, self::COUNTRY);
        $location = new Location(
            $profile,
            $town,
            strtolower(self::COUNTRY . '-' . $town),
            strtolower($town),
        );
        $location->setRegion('R');
        $location->setPopulation(120_000);
        $location->setCoordinates(53.0, -2.0);
        $location->setLandPence($landPence);
        $location->setLandValuedAt(new \DateTimeImmutable());

        $this->em->persist($location);

        $plot = new LandPlot($location, 1);
        $this->em->persist($plot);
        $this->em->flush();

        $user  = $this->user(creditPence: 90_000_000_00);
        $hold  = self::getContainer()->get(PlotHoldService::class)->acquire($user, $plot);
        $claim = self::getContainer()->get(PlotClaimService::class)
            ->claimFromHold($user, (string) $hold->getId());

        return [$claim, $location, $user];
    }

    private function club(int $balancePence): Club
    {
        $club = new Club('Sell FC ' . bin2hex(random_bytes(3)), $this->user());
        $club->setBalance($balancePence);

        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function facility(Club $club, string $slug, int $level, int $baseCost): void
    {
        $template = new FacilityTemplate();
        $template->setSlug($slug . '_' . bin2hex(random_bytes(3)));
        $template->setLabel('Test');
        $template->setDescription('Test');
        $template->setBaseCost($baseCost);

        $this->em->persist($template);

        $facility = new ClubFacility($club, $template->getSlug());
        $facility->setLevel($level);

        $this->em->persist($facility);
        $this->em->flush();
    }

    private function user(int $creditPence = 0): User
    {
        $user = new User('sell-' . bin2hex(random_bytes(5)) . '@test.local');
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
                'sell-grant-' . bin2hex(random_bytes(5)),
            );
        }

        return $user;
    }

    private function purge(): void
    {
        $c  = $this->connection;
        $cc = self::COUNTRY;
        $em = "email LIKE 'sell-%@test.local'";

        $c->executeStatement("UPDATE club SET home_location_id = NULL WHERE home_location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM plot_claim_valuation WHERE claim_id IN (SELECT pc.id FROM plot_claim pc JOIN location l ON l.id = pc.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM plot_claim WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM plot_hold WHERE plot_id IN (SELECT p.id FROM land_plot p JOIN location l ON l.id = p.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM stadium_estimate_input WHERE estimate_id IN (SELECT e.id FROM stadium_estimate e JOIN location l ON l.id = e.location_id WHERE l.country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM stadium_estimate WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => $cc]);
        $c->executeStatement("DELETE FROM club_facility WHERE club_id IN (SELECT c.id FROM club c JOIN \"user\" u ON u.id = c.user_id WHERE u.$em)");
        $c->executeStatement("DELETE FROM facility_template WHERE slug LIKE 'test\_stand\_%'");
        $c->executeStatement("DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM \"user\" WHERE $em)");
        $c->executeStatement("DELETE FROM leaderboard_entry WHERE club_id IN (SELECT c.id FROM club c JOIN \"user\" u ON u.id = c.user_id WHERE u.$em)");
        $c->executeStatement("DELETE FROM club WHERE user_id IN (SELECT id FROM \"user\" WHERE $em)");
        $c->executeStatement("DELETE FROM \"user\" WHERE $em");
        $c->executeStatement('DELETE FROM location WHERE country_code = :x', ['x' => $cc]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :x', ['x' => $cc]);
    }
}

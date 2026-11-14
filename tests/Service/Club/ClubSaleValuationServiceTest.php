<?php

declare(strict_types=1);

namespace App\Tests\Service\Club;

use App\Entity\Club;
use App\Entity\ClubFacility;
use App\Entity\FacilityTemplate;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\PlotClaim;
use App\Entity\User;
use App\Service\Club\ClubSaleValuationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The buyout estimate, and in particular the four ways it can price the land.
 *
 * The land bases are the point of this file. Everything else in the formula is arithmetic over
 * figures the club carries directly, but the land term can be the largest one and is the only
 * term whose SOURCE varies — a club may hold a plot, be linked to a town, be matchable only by
 * name, or be unplaceable. Three of those four are reached by clubs this feature exists to pay.
 */
class ClubSaleValuationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ClubSaleValuationService $valuation;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em        = self::getContainer()->get(EntityManagerInterface::class);
        $this->valuation = self::getContainer()->get(ClubSaleValuationService::class);
    }

    protected function tearDown(): void
    {
        // Identifier read through Doctrine rather than a getId() call: CountryProfile is keyed
        // on its two-letter code and has no getId() at all, so the usual helper would throw
        // here and leave every other row in this test behind with it.
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

    private function club(string $name, int $balance = 0, int $reputation = 0, ?string $country = null): Club
    {
        $user = new User('valuation-' . bin2hex(random_bytes(6)) . '@example.test');
        $user->setPassword('x');
        $this->persist($user);

        $club = new Club($name, $user);
        $club->setBalance($balance);
        $club->setReputation($reputation);

        if ($country !== null) {
            $club->setCountry($country);
        }

        $this->persist($club);
        $this->em->flush();

        return $club;
    }

    /**
     * A country and one town in it, with a land price.
     *
     * The country code is randomised per call for the reason AccountDeletionServiceTest spells
     * out about League: these rows carry uniqueness constraints over a small code space, and a
     * hardcoded code leaks across runs and breaks unrelated tests later.
     */
    private function town(string $townName, ?int $landPence): Location
    {
        // Two random uppercase letters. Randomised per call for the reason
        // AccountDeletionServiceTest spells out about League: these rows carry uniqueness
        // constraints over a 676-code space, and a hardcoded code leaks across runs and breaks
        // unrelated tests later.
        $letters = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        $code    = $letters[random_int(0, 25)] . $letters[random_int(0, 25)];

        $profile = $this->em->getRepository(CountryProfile::class)->findOneBy(['code' => $code]);

        if ($profile === null) {
            $profile = new CountryProfile($code, 'Valuation Test ' . $code);
            $this->persist($profile);
        }

        // The slug must be unique per country, so it carries the randomness rather than the
        // town name — the name is what the matcher reads and has to stay readable.
        $location = new Location(
            $profile,
            $townName,
            strtolower($townName) . '-' . bin2hex(random_bytes(4)),
            strtolower($townName),
        );

        if ($landPence !== null) {
            $location->setLandPence($landPence);
        }

        $this->persist($location);
        $this->em->flush();

        return $location;
    }

    // ── The formula ──────────────────────────────────────────────────────────

    public function testBalanceAndFacilitiesMakeTheBaseAndGenerosityIsAppliedLast(): void
    {
        $club = $this->club('Formula Town FC', 100_000);

        $template = new FacilityTemplate('vt-' . bin2hex(random_bytes(4)), 'Training', '', 'TRAINING', 1_000);
        $this->persist($template);
        $this->persist(new ClubFacility($club, $template->getSlug(), 2));
        $this->em->flush();

        $b = $this->valuation->breakdown($club);

        // baseCost x L(L+1)/2 = 1000 x 3 = 3000, the curve FacilityCostCalculator owns.
        self::assertSame(3_000, $b['facilitiesPence']);
        self::assertSame(100_000, $b['balancePence']);
        self::assertSame(103_000, $b['basePence']);

        // No reputation, so the only multiplier is generosity.
        self::assertSame(0.0, $b['reputationFactor']);
        self::assertSame(
            (int) round(103_000 * ClubSaleValuationService::GENEROSITY_MULTIPLIER),
            $b['estimatePence'],
        );
    }

    public function testReputationUpliftScalesWithTheBaseAndIsCapped(): void
    {
        $modest = $this->club('Modest Town FC', 100_000, 1_000);
        $absurd = $this->club('Absurd Town FC', 100_000, 50_000_000);

        $m = $this->valuation->breakdown($modest);
        $a = $this->valuation->breakdown($absurd);

        self::assertSame(0.2, $m['reputationFactor'], '1,000 pts at 0.0002 each is +20%');
        self::assertFalse($m['reputationCapped']);

        // The cap is the whole reason it exists: club.reputation is an unsigned bigint with no
        // clamp in the entity and is device-reported, so without this a client bug is a payout.
        self::assertSame(ClubSaleValuationService::REPUTATION_UPLIFT_CAP, $a['reputationFactor']);
        self::assertTrue($a['reputationCapped']);
    }

    public function testNegativeBalanceFloorsTheBaseAtZeroRatherThanMultiplyingADebt(): void
    {
        // The device owns club.balance and it goes negative when a client overspends. Without the
        // floor, the generosity multiplier would make the debt bigger and round() would hand back
        // a negative "payout".
        $club = $this->club('Overdrawn Town FC', -500_000);

        $b = $this->valuation->breakdown($club);

        self::assertSame(-500_000, $b['balancePence'], 'the real figure is still reported');
        self::assertSame(0, $b['basePence']);
        self::assertSame(0, $b['estimatePence']);
    }

    // ── The four land bases ──────────────────────────────────────────────────

    public function testLandComesFromTheLinkedTownWhenThereIsNoClaim(): void
    {
        $location = $this->town('Linkville', 4_000_000);
        $club     = $this->club('Linkville FC');
        $club->setHomeLocation($location);
        $this->em->flush();

        $b = $this->valuation->breakdown($club);

        self::assertSame('home_location', $b['land']['basis']);
        self::assertSame(4_000_000, $b['landPence']);
        self::assertSame('Linkville', $b['land']['town']);
    }

    public function testLandIsTakenAtFullValueFromAnActivePlotClaim(): void
    {
        $location = $this->town('Claimtown', 2_000_000);

        $plot = new LandPlot($location, 1);
        $plot->setLandPenceOverride(7_500_000);
        $this->persist($plot);

        $club = $this->club('Claimtown United');
        $club->setHomeLocation($location);
        $this->em->flush();

        // Built directly rather than through PlotClaimRepository::insertIfUnclaimed(), whose
        // INSERT deliberately carries no club_id — the club is attached afterwards by the
        // founding flow. attachClub() is that step, and it freezes the name.
        $claim = new PlotClaim($plot, $club->getUser(), 7_500_000);
        $claim->attachClub($club);
        $claim->setStadiumPencePaid(1_000_000);
        $this->persist($claim);
        $this->em->flush();

        $b = $this->valuation->breakdown($club);

        self::assertSame('active_claim', $b['land']['basis']);
        // The plot override wins over the town price, and nothing is discounted — a buyout is a
        // one-way exit, so PlotSellBackService's 0.9x/0.5x coefficients do not apply.
        self::assertSame(7_500_000, $b['landPence']);
        self::assertSame(1_000_000, $b['stadiumPence']);
    }

    public function testLandIsInferredFromTheClubNameWhenNothingIsLinked(): void
    {
        // The case this feature exists for: a club founded before the land economy has no
        // home_location_id at all, and the town is recoverable only from its name.
        $location = $this->town('Nameborough', 3_300_000);
        $club     = $this->club('Nameborough Rovers', 0, 0, $location->getCountryCode());

        $b = $this->valuation->breakdown($club);

        self::assertSame('name_match', $b['land']['basis']);
        self::assertSame(3_300_000, $b['landPence']);
        self::assertSame('Nameborough', $b['land']['town']);
        self::assertNotNull($b['land']['note'], 'an inferred town must carry a warning for the operator');
    }

    public function testAnUnplaceableClubGetsNoLandAndSaysSo(): void
    {
        $location = $this->town('Somewhere', 1_000_000);
        // A name that contains no town in the country, so neither pass of the matcher hits.
        $club = $this->club('Zzyzx Wanderers', 50_000, 0, $location->getCountryCode());

        $b = $this->valuation->breakdown($club);

        self::assertSame('unmatched', $b['land']['basis']);
        self::assertSame(0, $b['landPence']);
        self::assertNull($b['land']['town']);
        self::assertStringContainsString('set the figure by hand', (string) $b['land']['note']);
    }

    public function testTheWorkingCarriesItsModelVersionAndTheStalenessWarning(): void
    {
        // The working is stored verbatim on club_sale.working_json and read months later by
        // somebody holding a support ticket. It has to say which formula produced it, and that
        // the figures came off a device.
        $b = $this->valuation->breakdown($this->club('Version Town FC'));

        self::assertSame(ClubSaleValuationService::MODEL_VERSION, $b['modelVersion']);
        self::assertNotEmpty($b['stale']);
        self::assertStringContainsString('last sync', $b['stale'][0]);
        self::assertArrayHasKey('modelVersion', $b['club'], 'the nested club model carries its own version');
    }
}

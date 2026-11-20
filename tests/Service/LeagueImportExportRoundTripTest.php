<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\League;
use App\Entity\NpcClub;
use App\Enum\CitySize;
use App\Enum\Formation;
use App\Enum\ReputationTier;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Service\LeagueImportExportService;
use App\Service\TrophyConfigFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The world export is a hand-maintained field list, so it drifts as League and NpcClub grow
 * — citySize, populationSize, isCapital, region and abbreviation were all added to NpcClub
 * and never reached it, which meant a clear-then-import world round-trip quietly reset every
 * NPC club's city data to defaults.
 */
class LeagueImportExportRoundTripTest extends KernelTestCase
{
    /** Distinctive so it cannot collide with another test's synthetic country. */
    private const COUNTRY = 'ZQ';

    /**
     * A SECOND synthetic country, for the league that INHERITS its payouts.
     *
     * Kept apart from `COUNTRY` deliberately: that fixture seeds an explicit override on both
     * payout columns, which is the opposite of the state the round-trip trap is about, and the
     * existing tests index its export row positionally.
     */
    private const INHERIT_COUNTRY = 'ZY';

    /**
     * A league whose TV is DERIVED: a pool is authored and the per-club column is NULL, which is
     * the live state of Scottish tiers 5-8. ⚠️ The divisor comes from the NPC club rows this
     * fixture inserts — `slots` was retired on 2026-11-19 and `POOL_SLOTS` is now the ROW COUNT
     * the fixture seeds, kept under its old name because the expected `POOL_DERIVED` is
     * `POOL_PENCE / POOL_SLOTS` and renaming both would obscure that.
     */
    private const POOL_COUNTRY = 'ZP';
    private const POOL_PENCE   = 1_000_000;
    private const POOL_SLOTS   = 10;
    private const POOL_DERIVED = 100_000;

    private LeagueImportExportService $service;
    private LeagueRepository $leagues;
    private NpcClubRepository $clubs;
    private EntityManagerInterface $em;
    private \App\Service\Economy\LeaguePayoutResolver $payouts;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(LeagueImportExportService::class);
        $this->leagues = self::getContainer()->get(LeagueRepository::class);
        $this->clubs   = self::getContainer()->get(NpcClubRepository::class);
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->payouts = self::getContainer()->get(\App\Service\Economy\LeaguePayoutResolver::class);

        $this->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();
        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string, string, string[]}>
     */
    public static function worldEntityProvider(): array
    {
        return [
            // id and createdAt are server-side identity/bookkeeping; league association is
            // rebuilt from country+tier by relinkClubsToLeagues() rather than serialized.
            //
            // ⚠️ `slots` IS EXCLUDED DELIBERATELY, AND IT IS THE ONLY EXCLUSION HERE THAT IS A
            // DECISION RATHER THAN BOOKKEEPING. Owner ruling, 2026-11-19: *"Retire slots."*
            // Nothing reads the column — `LeagueSizeResolver::sizeOf()` derives every division
            // size from the NPC club rows, which this export carries under `clubs`, so the size
            // DOES round-trip, through the rows. Listing it here rather than emitting it keeps
            // the round-trip invariant exact instead of carving a second exception into
            // testWorldSurvivesClearAndReimport. ⚠️ If `slots` is ever read again, it must come
            // off this list in the same change.
            'League'  => [League::class, 'leagues', ['createdAt', 'slots']],
            'NpcClub' => [NpcClub::class, 'clubs', ['createdAt', 'league']],
        ];
    }

    /**
     * The drift guard: a new column on League or NpcClub must appear in the world export.
     *
     * @param string[] $excluded
     */
    #[DataProvider('worldEntityProvider')]
    public function testEveryMappedColumnIsExported(string $class, string $section, array $excluded): void
    {
        $this->seed();
        $exported = $this->service->export()[$section][0] ?? null;
        self::assertIsArray($exported, 'Fixture did not reach the export.');

        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            if ($property->getAttributes(ORM\Column::class) === []
                || $property->getAttributes(ORM\Id::class) !== []
                || in_array($property->getName(), $excluded, true)) {
                continue;
            }

            self::assertArrayHasKey(
                $property->getName(),
                $exported,
                sprintf(
                    '%s::$%s is persisted but missing from the world export, so a '
                    . 'clear-then-import round-trip silently resets it.',
                    (new \ReflectionClass($class))->getShortName(),
                    $property->getName(),
                ),
            );
        }
    }

    /** Whatever the export emits must come back unchanged after a full clear-and-restore. */
    public function testWorldSurvivesClearAndReimport(): void
    {
        $this->seed();
        $exported = $this->service->export();

        $this->removeFixtures();
        self::assertCount(0, $this->clubs->findBy(['country' => self::COUNTRY]));

        $result = $this->service->import($exported);
        $this->em->clear();

        self::assertSame([], $result['errors']);
        self::assertTrue($result['applied']);

        $restored = $this->service->export();
        self::assertEquals(
            $this->onlyFixtures($exported),
            $this->onlyFixtures($restored),
            'A field the world export emits is not applied on import.',
        );
    }

    /** Regression: the city-size fields were lost entirely on every world re-import. */
    public function testCityDataSurvivesReimport(): void
    {
        $this->seed();
        $exported = $this->service->export();
        $this->removeFixtures();

        $this->service->import($exported);
        $this->em->clear();

        $club = $this->clubs->findOneBy(['country' => self::COUNTRY, 'name' => 'Probe Rovers']);

        self::assertSame(CitySize::BIG, $club->getCitySize());
        self::assertSame(1_234_567, $club->getPopulationSize());
        self::assertTrue($club->isCapital());
        self::assertSame('Probe Region', $club->getRegion());
        self::assertSame('PRV', $club->getAbbreviation());
        self::assertSame('hoops', $club->getIdentity()['home']['kit']);
        self::assertSame('plain', $club->getIdentity()['away']['kit']);
        self::assertSame('round', $club->getIdentity()['badgeShape']);
        self::assertSame('#1f8a4c', $club->getPrimaryColor(), 'primaryColor must stay synced to the home kit after reimport');

        $league = $this->leagues->findOneBy(['country' => self::COUNTRY]);
        self::assertSame(6, $league->getSponsorCount());
        self::assertSame(
            ['style' => 'loving', 'finish' => 'gold', 'ribbons' => true, 'ribbonPrimary' => '#c8202f', 'ribbonSecondary' => '#f4f3ee'],
            $league->getTrophy(),
        );
    }

    /** A malformed row is reported, and the rest of the file still applies. */
    public function testBadRowIsReportedWithoutBlockingTheRest(): void
    {
        $this->seed();
        $exported = $this->service->export();
        $this->removeFixtures();

        $exported['clubs'][] = [
            'name'     => 'Broken United',
            'country'  => self::COUNTRY,
            'tier'     => 4,
            'citySize' => 'NOT_A_CITY_SIZE',
        ];

        $result = $this->service->import($exported);
        $this->em->clear();

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('Broken United', $result['errors'][0]);
        self::assertNotNull($this->clubs->findOneBy(['country' => self::COUNTRY, 'name' => 'Probe Rovers']));

        // The rejected row must not survive as a half-built entity: NpcClub is constructed
        // and persisted before the enum is parsed, so without detaching it the flush would
        // write a club whose import the operator was just told had failed.
        self::assertNull(
            $this->clubs->findOneBy(['country' => self::COUNTRY, 'name' => 'Broken United']),
            'A rejected row was persisted anyway.',
        );
    }

    /**
     * The verbatim block specifically, through a full clear and restore.
     *
     * testWorldSurvivesClearAndReimport already compares the whole export against itself, so
     * this is narrower on purpose: it names the ONE column whose contract is "whatever was
     * given back, byte for byte". A generic equality pass would still go green if both sides
     * had been normalised the same way, which is exactly the regression worth catching.
     */
    public function testTheParachuteBlockSurvivesAClearAndReimportVerbatim(): void
    {
        $this->seed();

        $before = $this->service->export();
        $sent   = $this->leagueRowIn($before);

        $this->removeFixtures();
        $result = $this->service->import($before);
        $this->em->clear();

        self::assertSame([], $result['errors']);

        $after = $this->leagueRowIn($this->service->export());

        self::assertSame($sent['parachute'], $after['parachute'], 'the parachute block was altered in transit');
        self::assertSame(3, $after['parachute']['seasons']);
        self::assertSame(['nested' => true], $after['parachute']['appOwnedKey'], 'a key the backend does not know must survive');
        self::assertSame(9_000_000, $after['tvPoolPence']);
        // ⚠️ `slots` IS NO LONGER ASSERTED HERE, AND NO LONGER IN THE EXPORT AT ALL — owner
        // ruling, 2026-11-19, "retire slots". The line it replaces was `assertSame(18,
        // $after['slots'])`. The division size still round-trips, through the `clubs` rows the
        // same export carries; see worldEntityProvider()'s exclusion list.
        self::assertArrayNotHasKey('slots', $after, 'the retired column came back into the export');
        self::assertSame(17, $after['leaguePositionDecreasePercent']);
    }

    /**
     * ⚠️ THE ROUND-TRIP TRAP, AND IT IS THE WHOLE RISK IN THE RESOLVED-PAYOUT CHANGE.
     *
     * `export()` emits RESOLVED payouts under `prizeMoney` / `leaguePositionPot`, because that
     * is the key the app's economy gate reads. If `import()` wrote those back into the columns,
     * round-tripping a world would convert every inherit (NULL) into a per-league override —
     * freezing the ppm ladder into 196 rows and defeating `5dc3f51b`'s NULL-means-inherit
     * design, silently and with no error. So the columns must still be NULL afterwards.
     */
    public function testARoundTripLeavesAnInheritedLeaguesPayoutColumnsNull(): void
    {
        $this->seedInheritingLeague();

        $exported = $this->service->export();
        $row      = $this->leagueRowIn($exported, self::INHERIT_COUNTRY);

        // The export really is carrying a resolved figure, not a null — otherwise this test
        // would pass for the wrong reason on a box with no authored ladder.
        self::assertNotNull(
            $row['prizeMoney'],
            'the ladder is unauthored on this box, so the trap cannot be exercised',
        );
        self::assertNull($row['prizeMoneyOverride'], 'the raw column must export as null');
        self::assertNull($row['leaguePositionPotOverride']);

        $this->removeFixtures();
        $result = $this->service->import($exported);
        $this->em->clear();

        self::assertSame([], $result['errors']);

        $restored = $this->inheritingLeague();

        self::assertNull(
            $restored->getPrizeMoney(),
            'the resolved prize was written into the column — the ladder is now frozen as an override',
        );
        self::assertNull(
            $restored->getLeaguePositionPot(),
            'the resolved position pot was written into the column — the ladder is now frozen as an override',
        );
    }

    /**
     * A fresh export's `prizeMoney` must EQUAL the resolver, not merely be non-zero.
     *
     * Non-zero was the bar the blocker was found against and it is too weak: the raw column read
     * £0, so anything non-zero would have looked like a fix while still being the wrong figure.
     */
    public function testAFreshExportsPayoutsEqualTheResolver(): void
    {
        $this->seedInheritingLeague();

        $league = $this->inheritingLeague();
        $row    = $this->leagueRowIn($this->service->export(), self::INHERIT_COUNTRY);

        self::assertSame($this->payouts->prizeFor($league), $row['prizeMoney']);
        self::assertSame($this->payouts->positionPotFor($league), $row['leaguePositionPot']);
    }

    /** An explicit override still wins, and still survives the round trip as an override. */
    public function testAnExplicitOverrideSurvivesTheRoundTripAsAnOverride(): void
    {
        $this->seed();

        $row = $this->leagueRowIn($this->service->export());
        self::assertSame(250_000, $row['prizeMoney'], 'an override resolves to itself');
        self::assertSame(250_000, $row['prizeMoneyOverride']);
        self::assertSame(75_000, $row['leaguePositionPotOverride']);

        $exported = $this->service->export();
        $this->removeFixtures();
        self::assertSame([], $this->service->import($exported)['errors']);
        $this->em->clear();

        $restored = $this->leagues->findOneBy(['country' => self::COUNTRY, 'tier' => 4]);

        self::assertSame(250_000, $restored->getPrizeMoney(), 'a real override must not be lost');
        self::assertSame(75_000, $restored->getLeaguePositionPot());
    }

    /**
     * A file written BEFORE the resolved-payout change still restores its columns.
     *
     * Such a row has no `*Override` key at all and its `prizeMoney` IS the raw column, so the
     * fallback must fire. It is keyed on the override key's PRESENCE, which is what stops a new
     * file — where the key is always present, often null — from ever reaching it.
     */
    public function testALegacyFileWithoutTheOverrideKeysStillRestoresItsColumns(): void
    {
        $this->seed();
        $exported = $this->service->export();

        foreach ($exported['leagues'] as $i => $row) {
            if (($row['country'] ?? null) === self::COUNTRY) {
                unset(
                    $exported['leagues'][$i]['prizeMoneyOverride'],
                    $exported['leagues'][$i]['leaguePositionPotOverride'],
                );
            }
        }

        $this->removeFixtures();
        self::assertSame([], $this->service->import($exported)['errors']);
        $this->em->clear();

        $restored = $this->leagues->findOneBy(['country' => self::COUNTRY, 'tier' => 4]);

        self::assertSame(250_000, $restored->getPrizeMoney(), 'a pre-change backup lost its override');
        self::assertSame(75_000, $restored->getLeaguePositionPot());
    }

    /** @param array<string, mixed> $export */
    private function leagueRowIn(array $export, ?string $country = null): array
    {
        $country ??= self::COUNTRY;

        foreach ($export['leagues'] ?? [] as $row) {
            if (($row['country'] ?? null) === $country) {
                return $row;
            }
        }

        self::fail('The seeded league is missing from the export.');
    }

    private function seed(): void
    {
        $league = new League(self::COUNTRY, 4, 'Probe Division');
        $league->setPromotionSpots(2);
        $league->setTvDeal(500_000);
        $league->setPrizeMoney(250_000);
        $league->setLeaguePositionPot(75_000);
        $league->setLeagueReputationTier(ReputationTier::REGIONAL);
        $league->setSponsorCount(6);
        $league->setTrophy(TrophyConfigFactory::validate(['style' => 'loving', 'finish' => 'gold']));
        // THE NEW COLUMNS, SEEDED NON-DEFAULT ON PURPOSE. testWorldSurvivesClearAndReimport
        // compares the export against itself after a full clear and restore, so a column left
        // at its default would round-trip successfully while being dropped entirely — the
        // failure that guard exists to catch.
        //
        // The parachute block is deliberately AWKWARD: nested, mixed types, and a key nothing
        // on the backend knows. It is served verbatim, so the round trip has to carry a shape
        // the backend cannot interpret, not a tidy one it might normalise by accident.
        $league->setTvPoolPence(9_000_000);
        $league->setSlots(18);
        $league->setLeaguePositionDecreasePercent(17);
        $league->setParachute([
            'seasons'        => 3,
            'perSeasonPence' => [5_500_000_00, 4_500_000_00, 2_000_000_00],
            'appOwnedKey'    => ['nested' => true],
        ]);
        $this->em->persist($league);

        $club = new NpcClub(
            'Probe Rovers',
            self::COUNTRY,
            4,
            42,
            '#112233',
            '#445566',
            9_000_000,
            ['training' => 3],
            'Probe Region',
            CitySize::BIG,
            1_234_567,
            true,
        );
        $club->setAbbreviation('PRV');
        $club->setStadiumName('Probe Park');
        $club->setPlayingStyle('POSSESSION');
        $club->setFinancialApproach('CAUTIOUS');
        $club->setManagerTemperament(61);
        $club->setFormation(Formation::F_433);
        $club->setIdentity([
            'home' => ['kit' => 'hoops', 'primary' => '#1f8a4c', 'secondary' => '#f4f3ee', 'shorts' => 'white', 'socks' => 'primary'],
            'away' => ['kit' => 'plain', 'primary' => '#1a1a1a', 'secondary' => '#f2c230', 'shorts' => 'black', 'socks' => 'black'],
            'badgeShape' => 'round', 'badgePattern' => 'stripes', 'badgeCentre' => 'initials',
            'initials' => 'PRV', 'badgeFill' => '#1b2a4a', 'badgeTrim' => '#f2c230', 'badgeSymbol' => '#f4f3ee',
        ]);
        $this->em->persist($club);

        $this->em->flush();
        $this->em->clear();
    }

    /** Ignores whatever else lives in the dev/test world. */
    private function onlyFixtures(array $export): array
    {
        $filter = static fn (array $rows) => array_values(array_filter(
            $rows,
            static fn (array $row) => ($row['country'] ?? null) === self::COUNTRY,
        ));

        return [
            'leagues' => $filter($export['leagues']),
            'clubs'   => $filter($export['clubs']),
        ];
    }

    private function removeFixtures(): void
    {
        foreach ([self::COUNTRY, self::INHERIT_COUNTRY, self::POOL_COUNTRY] as $country) {
            foreach ($this->clubs->findBy(['country' => $country]) as $club) {
                $this->em->remove($club);
            }
        }
        $this->em->flush();

        foreach ([self::COUNTRY, self::INHERIT_COUNTRY, self::POOL_COUNTRY] as $country) {
            foreach ($this->leagues->findBy(['country' => $country]) as $league) {
                $this->em->remove($league);
            }
        }
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * The defect this closes: a pooled league published its NULL column, the app read
     * `Math.max(0, league.tvDealPence)` and paid £0 of TV.
     */
    public function testAPooledLeagueExportsTheDerivedShareNotItsNullColumn(): void
    {
        $this->seedPooledLeague();

        $row = $this->leagueRowIn($this->service->export(), self::POOL_COUNTRY);

        self::assertSame(
            self::POOL_DERIVED,
            $row['tvDeal'],
            'the export published the raw NULL column instead of the derived per-club share',
        );
        self::assertNull(
            $row['tvDealOverride'],
            'the raw column must export as null, or a round trip will freeze the derived share',
        );
    }

    /**
     * The export/import symmetry trap, for `tvDeal` this time: if the resolved share is read back
     * into the column, a round trip converts "derive from the pool" into a permanent per-league
     * override — silently, with no error.
     */
    public function testARoundTripLeavesAPooledLeaguesTvDealColumnNull(): void
    {
        $this->seedPooledLeague();

        $exported = $this->service->export();

        // Otherwise this passes for the wrong reason on a box where nothing derives.
        self::assertSame(self::POOL_DERIVED, $this->leagueRowIn($exported, self::POOL_COUNTRY)['tvDeal']);

        $this->removeFixtures();
        $result = $this->service->import($exported);
        $this->em->clear();

        self::assertSame([], $result['errors']);
        self::assertNull(
            $this->pooledLeague()->getTvDeal(),
            'the derived share was written into the column — this league no longer follows its pool',
        );
    }

    /**
     * The inert half, and the one that protects every league outside Scotland: all eight England
     * tiers author `tvDeal` directly with no pool, and must export byte-identically.
     */
    public function testAnUnpooledLeagueExportsItsAuthoredDealUnchanged(): void
    {
        $this->seedInheritingLeague();

        $row = $this->leagueRowIn($this->service->export(), self::INHERIT_COUNTRY);

        self::assertNull($this->inheritingLeague()->getTvPoolPence(), 'fixture must have no pool');
        self::assertSame(400_000, $row['tvDeal'], 'an authored deal must survive untouched');
        self::assertSame(400_000, $row['tvDealOverride'], 'and it is a genuine override, so it round-trips');
    }

    private function seedPooledLeague(): void
    {
        $league = new League(self::POOL_COUNTRY, 3, 'Pooled Division');
        $league->setPromotionSpots(2);
        // The retired column, still written so the fixture matches a real row on a box that
        // was authored before 2026-11-19. It does not reach the divisor — see below.
        $league->setSlots(self::POOL_SLOTS);
        $league->setTvPoolPence(self::POOL_PENCE);

        // NOT SET, AND THAT IS THE FIXTURE: nothing writes this column at generation, so a
        // pooled league carries NULL until an admin saves it.
        self::assertNull($league->getTvDeal());

        $this->em->persist($league);

        // ⚠️ THE DIVISOR COMES FROM THESE ROWS, NOT FROM `setSlots()` ABOVE — owner ruling,
        // 2026-11-19. `POOL_SLOTS - 1` of them, because tier 3 is the only league this country
        // has and is therefore its deepest, where `sizeOf()` adds the player's own place: nine
        // NPC rows plus the player is a division of ten, which is what `POOL_DERIVED` divides
        // by. Before the ruling this fixture needed no clubs at all, which is exactly the
        // dependency the ruling removed from the column and gave to the rows.
        for ($i = 0; $i < self::POOL_SLOTS - 1; $i++) {
            $club = new NpcClub(
                sprintf('Pooled %s-%d', self::POOL_COUNTRY, $i),
                self::POOL_COUNTRY,
                3,
                40,
                '#101010',
                '#202020',
                1_000_000,
                [],
            );
            $club->setLeague($league);
            $this->em->persist($club);
        }

        $this->em->flush();
        $this->em->clear();
    }

    private function pooledLeague(): League
    {
        $league = $this->leagues->findOneBy(['country' => self::POOL_COUNTRY, 'tier' => 3]);
        self::assertNotNull($league, 'the pooled fixture is missing');

        return $league;
    }

    /**
     * A league in the state all 196 real ones are in: both payout columns NULL, inheriting.
     *
     * Returned flushed and detached, so a caller reads it back from the repository rather than
     * from an identity map that already holds the values it is trying to prove.
     */
    private function seedInheritingLeague(): void
    {
        $league = new League(self::INHERIT_COUNTRY, 4, 'Inherit Division');
        $league->setPromotionSpots(2);
        $league->setTvDeal(400_000);

        // NOT SET, AND THAT IS THE FIXTURE: NULL means inherit from the ppm ladder.
        self::assertNull($league->getPrizeMoney());
        self::assertNull($league->getLeaguePositionPot());

        $this->em->persist($league);
        $this->em->flush();
        $this->em->clear();
    }

    private function inheritingLeague(): League
    {
        $league = $this->leagues->findOneBy(['country' => self::INHERIT_COUNTRY, 'tier' => 4]);
        self::assertNotNull($league, 'the inheriting fixture is missing');

        return $league;
    }
}

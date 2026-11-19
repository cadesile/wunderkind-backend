<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\NpcClub;
use App\Enum\CitySize;
use App\Service\WorldPackSnapshotBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The `land` block on an NPC club in the world pack.
 *
 * ADDITIVE, AND THAT IS THE PART WORTH TESTING. The frontend ships behind the backend, so a
 * new key may appear but nothing a client already reads may move or change meaning — see the
 * API-compatibility rule. These pin that `region`, `citySize`, `populationSize` and
 * `isCapital` are all still exactly where they were, alongside the new block.
 *
 * ── CHANGED AT v11 (2026-11-18): THE BLOCK KEEPS THE GROUND, LOSES THE PRICE ─────────────
 *
 * `land.valuePence` and the club's `startingBalance` were both removed here, deliberately and
 * in co-ordination with the app, because a world pack now carries no money at all —
 * `WorldPackSnapshotBuilder`'s class docblock has the why, `WorldPackCarriesNoMoneyTest` pins
 * the invariant.
 *
 * What these tests still guard is the part that MATTERED about the land block and did not
 * change: a seated club reports its plot, an unseated one reports its town with a null plot
 * number, a club with no town reports null. That is census data — WHERE a club is — and it is
 * exactly what a pack is for. What the ground is WORTH is a live, operator-authored figure
 * that `GET /api/locations` quotes on demand, which is where a client that wants to buy land
 * already reads it; baking it into a cached payload meant re-pricing one town invalidated
 * every pack in the country.
 *
 * The `valuePence` assertions below are therefore inverted rather than deleted: they now pin
 * that the key is ABSENT, including in the one case that used to prove the fallback chain
 * (a plot override beating the town value). That chain still exists in
 * `LandPlot::getEffectiveLandPence()` and is tested where it belongs, on the live surface.
 */
class WorldPackLandSnapshotTest extends KernelTestCase
{
    private const CODE = 'WL';

    private EntityManagerInterface $em;
    private WorldPackSnapshotBuilder $builder;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(WorldPackSnapshotBuilder::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $c = self::getContainer()->get(Connection::class);
        $c->executeStatement('UPDATE npc_club SET land_plot_id = NULL WHERE country = :c', ['c' => self::CODE]);
        $c->executeStatement('DELETE FROM npc_club WHERE country = :c', ['c' => self::CODE]);
        $c->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::CODE],
        );
        $c->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::CODE]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::CODE]);
        $this->em->clear();
    }

    private function seed(bool $withPlot, ?int $plotOverridePence = null): NpcClub
    {
        $profile = new CountryProfile(self::CODE, 'Worldland');
        $this->em->persist($profile);

        $location = new Location($profile, 'London', self::CODE . '-london', 'london');
        $location->setRegion('Greater London');
        $location->setPopulation(8_982_000);
        $location->setCoordinates(51.5, -0.12);
        $location->setLandPence(15_000_000_000);
        $location->setLandValuedAt(new \DateTimeImmutable());
        $location->setIsCapital(true);
        $this->em->persist($location);

        $plot = null;
        if ($withPlot) {
            $plot = new LandPlot($location, 1);
            if ($plotOverridePence !== null) {
                $plot->setLandPenceOverride($plotOverridePence);
            }
            $this->em->persist($plot);
        }

        $club = new NpcClub(
            name: 'London FC', country: self::CODE, tier: 1, reputation: 85,
            primaryColor: '#111111', secondaryColor: '#eeeeee', balance: 5_000_000,
            facilities: [], region: 'Greater London', citySize: CitySize::BIG,
            populationSize: 8_982_000, isCapital: true, location: $location,
        );
        $club->setLandPlot($plot);

        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    public function testASeatedClubCarriesItsTownAndItsPlot(): void
    {
        $snapshot = $this->builder->buildClubSnapshot($this->seed(withPlot: true), [], [], []);

        self::assertSame(
            [
                'name'       => 'London',
                'region'     => 'Greater London',
                'plotNumber' => 1,
                'capital'    => true,
            ],
            $snapshot['land'],
            'the land block is the town and the plot — and since v11, nothing priced',
        );
    }

    /** An unseated club still reports its town — it just has no plot number to give. */
    public function testAnUnseatedClubReportsTheTownWithANullPlotNumber(): void
    {
        $snapshot = $this->builder->buildClubSnapshot($this->seed(withPlot: false), [], [], []);

        self::assertSame('London', $snapshot['land']['name']);
        self::assertNull($snapshot['land']['plotNumber']);
        self::assertArrayNotHasKey('valuePence', $snapshot['land'], 'no price in a pack since v11');
    }

    /**
     * ⚠️ THIS TEST USED TO ASSERT THE OPPOSITE, and the inversion is the point.
     *
     * It pinned that a plot's own price beat the town's, so "the pack and the shop agree on
     * what this ground costs". At v11 the pack quotes no cost at all, so there is nothing left
     * to agree — the shop is the only quote, and agreement is now structural rather than
     * maintained by two code paths resolving the same chain.
     *
     * Kept, inverted, rather than deleted: a priced plot is precisely the fixture most likely
     * to put a price back into a pack, so this is the case worth holding.
     * `LandPlot::getEffectiveLandPence()` is unchanged and still resolves override-then-town
     * for the live surface.
     */
    public function testAPricedPlotStillPutsNoPriceInThePack(): void
    {
        $snapshot = $this->builder->buildClubSnapshot(
            $this->seed(withPlot: true, plotOverridePence: 20_000_000_000),
            [], [], [],
        );

        self::assertArrayNotHasKey('valuePence', $snapshot['land']);
        self::assertSame(1, $snapshot['land']['plotNumber'], 'the plot itself is still reported');
    }

    /** A club generated before its country had locations has no town, and says so. */
    public function testAClubWithNoTownHasNullLand(): void
    {
        $club = new NpcClub(
            name: 'Capital FC', country: self::CODE, tier: 4, reputation: 40,
            primaryColor: '#111111', secondaryColor: '#eeeeee', balance: 1_000_000,
            facilities: [], citySize: CitySize::MEDIUM,
        );

        self::assertNull($this->builder->buildClubSnapshot($club, [], [], [])['land']);
    }

    /**
     * THE COMPATIBILITY ASSERTION. Everything a client reads today must still be there, with
     * the same name and the same meaning — `land` is an addition, not a replacement for the
     * four town fields that were already on the club.
     *
     * `startingBalance` LEFT THIS LIST AT v11, deliberately and with the app changed in the
     * same cut: a club's opening balance is money, and money is resolved on the device now. The
     * app reads its existing tier-scaled `npcStartingBalanceByTier` config ladder instead —
     * which `worldStore` already consulted as the fallback behind the pack's figure, so the
     * ladder is not new code, it is just no longer shadowed. Every other key here is still
     * pinned, and that is still the point of the test.
     */
    public function testTheExistingClubKeysAreUntouchedExceptTheBalance(): void
    {
        $snapshot = $this->builder->buildClubSnapshot($this->seed(withPlot: true), [], [], []);

        foreach ([
            'id', 'name', 'abbreviation', 'tier', 'reputation',
            'primaryColor', 'secondaryColor', 'identity', 'stadiumName', 'facilities',
            'region', 'citySize', 'populationSize', 'isCapital', 'personality',
            'players', 'staff', 'scouts',
        ] as $key) {
            self::assertArrayHasKey($key, $snapshot, "{$key} must survive the land addition");
        }

        self::assertArrayNotHasKey(
            'startingBalance',
            $snapshot,
            'the balance left the pack at v11 — the app derives it from the config tier ladder',
        );

        self::assertSame('Greater London', $snapshot['region']);
        self::assertTrue($snapshot['isCapital']);
        self::assertSame(8_982_000, $snapshot['populationSize']);
        self::assertSame('BIG', $snapshot['citySize']);
    }
}

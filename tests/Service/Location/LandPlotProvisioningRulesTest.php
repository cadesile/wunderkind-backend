<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\LocationConfig;
use App\Enum\CitySize;
use App\Repository\Location\LocationConfigRepository;
use App\Service\Location\LandPlotProvisioner;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Who gets plots, how many, and who gets none.
 *
 * TWO RULES, AND THE FIRST IS NEW. Plots follow PRICING: a town with no coordinates and no land
 * value stays at zero, because a plot it cannot sell is worse than no plot at all —
 * LocationCapacityService reports zero plots as `awaiting_enrichment`, which is the signal a
 * client reads to decide whether to offer a buy button, and one unsellable plot turns that
 * honest signal into an offer that fails at the checkout. Seeding used to create exactly that.
 *
 * The second rule is the count, which now comes from LocationConfig rather than a constant.
 */
class LandPlotProvisioningRulesTest extends KernelTestCase
{
    private const COUNTRY = 'TN';

    private EntityManagerInterface $em;
    private Connection $connection;
    private LandPlotProvisioner $provisioner;
    private LocationConfigRepository $configRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em               = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection       = self::getContainer()->get(Connection::class);
        $this->provisioner      = self::getContainer()->get(LandPlotProvisioner::class);
        $this->configRepository = self::getContainer()->get(LocationConfigRepository::class);

        $this->purge();
        $this->em->persist(new CountryProfile(self::COUNTRY, 'Plotland'));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    // ── The pricing gate ────────────────────────────────────────────────────

    public function testAnUnpricedTownGetsNoPlots(): void
    {
        // The whole point. It is active and classified, but it has no coordinates and no land
        // value, so it cannot be sold and must not look as though it can.
        $town = $this->town('Unpriced', CitySize::BIG, priced: false);

        self::assertSame(0, $this->provisioner->provisionLocation($town));
        self::assertSame(0, $this->plotCount($town));
    }

    public function testAnUnpricedTownIsSkippedByACountryWidePass(): void
    {
        // Same rule through the other entry point — the one `app:locations:provision-plots` and
        // the admin top-up button both use.
        $this->town('Unpriced', CitySize::BIG, priced: false);

        $result = $this->provisioner->provisionCountry(self::COUNTRY);

        self::assertSame(0, $result['plotsAdded']);
        self::assertSame(0, $result['locations']);
    }

    public function testATownWithCoordinatesButNoValueStillGetsNothing(): void
    {
        // Half-enriched is not enriched: PlotHoldService refuses on a null land value, so a plot
        // here would be exactly as unsellable as one on a town with no coordinates at all.
        $town = $this->town('HalfWay', CitySize::MEDIUM, priced: false);
        $town->setCoordinates(36.8, 10.1);
        $this->em->flush();

        self::assertSame(0, $this->provisioner->provisionLocation($town));
    }

    public function testAPricedTownGetsThePlotsItsSizeCallsFor(): void
    {
        $big = $this->town('Bigville', CitySize::BIG, priced: true);

        $added = $this->provisioner->provisionLocation($big);

        self::assertSame(4, $added, 'the configured default for BIG');
        self::assertSame(4, $this->plotCount($big));
    }

    public function testPricingATownIsWhatEarnsItPlots(): void
    {
        // The sequence the enrichment path actually performs: a town arrives bare, gets nothing,
        // then gains plots the moment it is valued.
        $town = $this->town('Later', CitySize::MEDIUM, priced: false);
        self::assertSame(0, $this->provisioner->provisionLocation($town));

        $town->setCoordinates(36.8, 10.1);
        $town->setLandPence(500_00);
        $town->setLandValuedAt(new \DateTimeImmutable());
        $this->em->flush();

        self::assertSame(2, $this->provisioner->provisionLocation($town));
    }

    // ── The configured count ────────────────────────────────────────────────

    public function testTheCountComesFromConfigRatherThanAConstant(): void
    {
        $this->setPlots([CitySize::BIG->value => 7, CitySize::MEDIUM->value => 3, CitySize::SMALL->value => 1]);

        $big = $this->town('Sevenville', CitySize::BIG, priced: true);

        self::assertSame(7, $this->provisioner->provisionLocation($big));
    }

    public function testLoweringTheConfigNeverRemovesAPlot(): void
    {
        // PROVISIONING still only ever adds. Lowering the configured count does not reach back
        // and retire plots: that is reapSurplus()'s job, and it runs on re-banding and on
        // release, not on a top-up pass. A config change alone therefore leaves the town as it
        // is until something reclassifies it.
        $big = $this->town('Keepsville', CitySize::BIG, priced: true);
        $this->provisioner->provisionLocation($big);
        self::assertSame(4, $this->plotCount($big));

        $this->setPlots([CitySize::BIG->value => 1, CitySize::MEDIUM->value => 1, CitySize::SMALL->value => 1]);

        self::assertSame(0, $this->provisioner->provisionLocation($big), 'nothing to add');
        self::assertSame(4, $this->plotCount($big), 'and nothing taken away');
    }

    // ── Reaping the surplus ─────────────────────────────────────────────────

    public function testReBandingDownRetiresThePlotsNobodyHolds(): void
    {
        // The gap this closes: a BIG town demoted to SMALL used to go on offering four plots
        // forever, selling land its size no longer justifies.
        $town = $this->town('Demoted', CitySize::BIG, priced: true);
        $this->provisioner->provisionLocation($town);
        self::assertSame(4, $this->activePlotCount($town));

        $town->setCitySize(CitySize::SMALL);
        $this->em->flush();

        self::assertSame(3, $this->provisioner->reapSurplus([(string) $town->getId()]));
        self::assertSame(1, $this->activePlotCount($town));
    }

    public function testTheLowestNumberedPlotIsTheOneThatSurvives(): void
    {
        // Arbitrary but fixed: the reap takes from the top so plot 1 is stable, which keeps a
        // town's oldest plot — the one most likely to be referenced elsewhere — in place.
        $town = $this->town('Ordered', CitySize::BIG, priced: true);
        $this->provisioner->provisionLocation($town);

        $town->setCitySize(CitySize::MEDIUM);
        $this->em->flush();
        $this->provisioner->reapSurplus([(string) $town->getId()]);

        self::assertSame([1, 2], $this->activePlotNumbers($town));
    }

    public function testRetiredRatherThanDeleted(): void
    {
        // land_plot is referenced by plot_claim with ON DELETE RESTRICT, so a DELETE would fail
        // on any plot that had ever been claimed. The row stays; it leaves the active count.
        $town = $this->town('Kept', CitySize::BIG, priced: true);
        $this->provisioner->provisionLocation($town);

        $town->setCitySize(CitySize::SMALL);
        $this->em->flush();
        $this->provisioner->reapSurplus([(string) $town->getId()]);

        self::assertSame(1, $this->activePlotCount($town), 'active');
        self::assertSame(4, $this->plotCount($town), 'rows, all four of them');
    }

    public function testReapingTwiceTakesNothingTheSecondTime(): void
    {
        $town = $this->town('Twice', CitySize::BIG, priced: true);
        $this->provisioner->provisionLocation($town);

        $town->setCitySize(CitySize::SMALL);
        $this->em->flush();

        self::assertSame(3, $this->provisioner->reapSurplus([(string) $town->getId()]));
        self::assertSame(0, $this->provisioner->reapSurplus([(string) $town->getId()]), 'idempotent');
    }

    public function testATownAtItsTargetIsLeftAlone(): void
    {
        $town = $this->town('Exact', CitySize::MEDIUM, priced: true);
        $this->provisioner->provisionLocation($town);

        self::assertSame(0, $this->provisioner->reapSurplus([(string) $town->getId()]));
        self::assertSame(2, $this->activePlotCount($town));
    }

    public function testACountryWideReapCoversEveryTown(): void
    {
        $a = $this->town('Alpha', CitySize::BIG, priced: true);
        $b = $this->town('Beta', CitySize::BIG, priced: true);
        $this->provisioner->provisionCountry(self::COUNTRY);

        // provisionCountry() ends in em->clear(), so both are detached by now and a setter on
        // them would write nothing. Re-find before editing.
        $a = $this->em->find(Location::class, $a->getId());
        $b = $this->em->find(Location::class, $b->getId());

        $a->setCitySize(CitySize::SMALL);
        $b->setCitySize(CitySize::SMALL);
        $this->em->flush();

        self::assertSame(6, $this->provisioner->reapSurplusForCountry(self::COUNTRY));
        self::assertSame(1, $this->activePlotCount($a));
        self::assertSame(1, $this->activePlotCount($b));
    }

    public function testReapingNoLocationsIsANoOpRatherThanAWholeTableSweep(): void
    {
        // An empty id list must not degrade into "every plot everywhere".
        $town = $this->town('Safe', CitySize::BIG, priced: true);
        $this->provisioner->provisionLocation($town);

        $town->setCitySize(CitySize::SMALL);
        $this->em->flush();

        self::assertSame(0, $this->provisioner->reapSurplus([]));
        self::assertSame(4, $this->activePlotCount($town));
    }

    public function testAConfiguredZeroMeansNoPlots(): void
    {
        // Expressible on purpose: an operator can close a size off entirely without code.
        $this->setPlots([CitySize::BIG->value => 0, CitySize::MEDIUM->value => 0, CitySize::SMALL->value => 0]);

        $town = $this->town('Closed', CitySize::BIG, priced: true);

        self::assertSame(0, $this->provisioner->provisionLocation($town));
    }

    public function testAnAbsurdConfiguredFigureIsClampedNotTrusted(): void
    {
        $config = $this->configRepository->getConfig();
        $config->setPlotsByCitySize([CitySize::BIG->value => 9_999]);
        $this->em->flush();

        self::assertSame(LocationConfig::MAX_PLOTS, $config->plotsForCitySize(CitySize::BIG));
    }

    public function testAGarbledStoredValueFallsBackToTheDefault(): void
    {
        // The row is editable, and a bad shape must not provision a nonsense number of plots —
        // nor zero, which would make a priced town read as awaiting enrichment.
        $config = $this->configRepository->getConfig();
        $config->setPlotsByCitySize([CitySize::BIG->value => 'not-a-number']);
        $this->em->flush();

        self::assertSame(
            LocationConfig::DEFAULT_PLOTS_BY_CITY_SIZE[CitySize::BIG->value],
            $config->plotsForCitySize(CitySize::BIG),
        );
    }

    public function testTheDefaultsStillMatchTheConstantsTheyReplaced(): void
    {
        // The guard on the move itself: an untouched installation must behave exactly as the
        // hardcoded const did, which is what lets the existing capacity tests keep asserting
        // against LocationCapacityService::plotsFor().
        $config = $this->configRepository->getConfig();

        foreach (CitySize::cases() as $size) {
            self::assertSame(
                \App\Service\Location\LocationCapacityService::plotsFor($size),
                $config->plotsForCitySize($size),
                "default for {$size->value} must match the constant it replaced",
            );
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @param array<string, int|string> $plots */
    private function setPlots(array $plots): void
    {
        $config = $this->configRepository->getConfig();
        $config->setPlotsByCitySize($plots);
        $this->em->flush();
    }

    private function town(string $name, CitySize $size, bool $priced): Location
    {
        $country  = $this->em->find(CountryProfile::class, self::COUNTRY);
        $location = new Location($country, $name, strtolower(self::COUNTRY . '-' . $name), strtolower($name));
        $location->setRegion('R');
        $location->setCitySize($size);
        $location->setPopulation(100_000);

        if ($priced) {
            $location->setCoordinates(36.8, 10.1);
            $location->setLandPence(1_000_00);
            $location->setLandValuedAt(new \DateTimeImmutable());
        }

        $this->em->persist($location);
        $this->em->flush();

        return $location;
    }

    private function activePlotCount(Location $town): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM land_plot WHERE location_id = :id AND status = 'active'",
            ['id' => (string) $town->getId()],
        );
    }

    /** @return list<int> */
    private function activePlotNumbers(Location $town): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT plot_number FROM land_plot
              WHERE location_id = :id AND status = 'active'
              ORDER BY plot_number",
            ['id' => (string) $town->getId()],
        ));
    }

    private function plotCount(Location $town): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM land_plot WHERE location_id = :id',
            ['id' => (string) $town->getId()],
        );
    }

    private function purge(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::COUNTRY],
        );
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::COUNTRY]);
        $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::COUNTRY]);
        // Config is a singleton shared with every other test, so it must go back to defaults.
        $this->connection->executeStatement('DELETE FROM location_config');
        $this->em->clear();
    }
}

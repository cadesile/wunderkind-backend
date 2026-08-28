<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\League;
use App\Enum\Country;
use App\Enum\LeaderboardCategory;
use App\Repository\StarterConfigRepository;
use App\Service\WorldOverviewService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The overview drives every number printed on the landing page, so the shape
 * and the unit conversions matter more than the values.
 */
class WorldOverviewServiceTest extends KernelTestCase
{
    private function overview(): array
    {
        self::bootKernel();

        return self::getContainer()->get(WorldOverviewService::class)->getOverview();
    }

    /**
     * Seeds one funded and one unfunded tier for a country nothing else uses.
     *
     * Without fixtures the money assertions iterate an empty tier list and pass
     * vacuously, which is worse than no test — it reports green while checking
     * nothing.
     *
     * @return array{0: array<string,mixed>, 1: string}
     */
    private function seededOverview(): array
    {
        self::bootKernel();
        $container = self::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $code = Country::SE->value;   // not in any default enabledCountries list
        foreach ($em->getRepository(League::class)->findBy(['country' => $code]) as $stale) {
            $em->remove($stale);
        }
        $em->flush();

        // Funded tier: whole pounds must come back, not pence.
        $funded = new League($code, 1, 'Test Elite');
        $funded->setPrizeMoney(500000)->setTvDeal(250000)->setLeaguePositionPot(125000);
        $em->persist($funded);

        // Unfunded tier: nulls must survive so the caller can omit the row
        // rather than print a misleading "£0".
        $em->persist(new League($code, 2, 'Test Second'));
        $em->flush();

        $config = $container->get(StarterConfigRepository::class)->getConfig();
        $original = $config->getEnabledCountries();
        $config->setEnabledCountries([$code]);
        $em->persist($config);
        $em->flush();

        $service = $container->get(WorldOverviewService::class);
        $service->clear();
        $data = $service->getOverview();

        // Restore, so this test cannot leak into the rest of the suite.
        $config->setEnabledCountries($original);
        $em->flush();
        $service->clear();

        return [$data, $code];
    }

    public function testExposesEveryKeyTheLandingPageReads(): void
    {
        $data = $this->overview();

        self::assertSame(
            ['countries', 'tiers', 'tiersByCountry', 'totals', 'starter', 'generatedAt'],
            array_keys($data)
        );
        self::assertSame(
            ['tierCount', 'npcClubs', 'leaderboardCategories', 'playableCountries', 'clubsPerTier'],
            array_keys($data['totals'])
        );
    }

    /**
     * The hero used to say 6 while four other places said 7. Deriving it from the
     * enum means the two can never disagree again.
     */
    public function testLeaderboardCountComesFromTheEnumNotFromCopy(): void
    {
        self::assertSame(
            count(LeaderboardCategory::cases()),
            $this->overview()['totals']['leaderboardCategories']
        );
    }

    public function testOnlyRecognisedCountryCodesAreAdvertised(): void
    {
        foreach ($this->overview()['countries'] as $country) {
            self::assertSame(['code', 'name'], array_keys($country));
            self::assertNotNull(Country::tryFrom($country['code']), "unknown code {$country['code']}");
            self::assertSame(Country::from($country['code'])->label(), $country['name']);
        }
    }

    public function testPlayableCountryCountMatchesTheCountryList(): void
    {
        $data = $this->overview();

        self::assertCount($data['totals']['playableCountries'], $data['countries']);
    }

    /** Money is stored as nullable bigint pence; the page needs whole pounds. */
    public function testMoneyIsConvertedToPoundsAndNullsSurvive(): void
    {
        [$data] = $this->seededOverview();

        self::assertCount(2, $data['tiers']);

        [$funded, $unfunded] = $data['tiers'];

        self::assertSame(5000, $funded['prizeMoney'], '500000 pence is £5,000');
        self::assertSame(2500, $funded['tvDeal']);
        self::assertSame(1250, $funded['leaguePositionPot']);

        self::assertNull($unfunded['prizeMoney'], 'an unset tier must stay null, not become 0');
        self::assertNull($unfunded['tvDeal']);
        self::assertNull($unfunded['leaguePositionPot']);
    }

    /** Only the enabled country appears, even though others exist in the table. */
    public function testDisabledCountriesAreExcluded(): void
    {
        [$data, $code] = $this->seededOverview();

        self::assertSame([$code], array_column($data['countries'], 'code'));
        self::assertSame(1, $data['totals']['playableCountries']);
    }

    public function testTierCountMatchesTheTierList(): void
    {
        $data = $this->overview();

        self::assertSame(count($data['tiers']), $data['totals']['tierCount']);
    }

    /**
     * The old copy asserted one flat club count for every tier. The summary must
     * express a range when the tiers actually differ.
     */
    public function testClubsPerTierSummarisesTheRealSpread(): void
    {
        $data   = $this->overview();
        $counts = array_filter(array_column($data['tiers'], 'clubs'), static fn (int $n): bool => $n > 0);

        if ($counts === []) {
            self::assertNull($data['totals']['clubsPerTier']);

            return;
        }

        $expected = min($counts) === max($counts)
            ? (string) min($counts)
            : min($counts) . '–' . max($counts);

        self::assertSame($expected, $data['totals']['clubsPerTier']);
    }

    public function testNpcClubTotalIsTheSumOfTheAdvertisedTiers(): void
    {
        $data = $this->overview();

        $summed = 0;
        foreach ($data['tiersByCountry'] as $tiers) {
            $summed += array_sum(array_column($tiers, 'clubs'));
        }

        self::assertSame($summed, $data['totals']['npcClubs']);
    }
}

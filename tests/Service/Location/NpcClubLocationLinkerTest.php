<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\Location;
use App\Entity\NpcClub;
use App\Enum\CitySize;
use App\Enum\Location\CountryProfileStatus;
use App\Service\ClubNameNormalizer;
use App\Service\Location\NpcClubLocationLinker;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The name-to-town matching rule, case by case.
 *
 * Every case here is a real name shape from the live catalogues, and each one breaks a different
 * naive implementation. The suffix-leading cases exist because the first cut of this rule
 * SHIPPED WRONG: it always preferred the prefix, which mis-linked the Saudi club "Al Riyadh
 * Hail" to Riyadh instead of Hail. Local data never produced a name that tripped it — dev did,
 * on 3,239 clubs — so these are written as explicit fixtures rather than left to whatever the
 * seeded data happens to contain.
 */
class NpcClubLocationLinkerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private NpcClubLocationLinker $linker;

    /** @var list<string> */
    private array $countries = ['ZA', 'ZB'];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->linker     = self::getContainer()->get(NpcClubLocationLinker::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    /**
     * A town-leading country: the affix happens to be another town's name.
     *
     * "Metz Amiens" in France — Amiens is a real town AND a generic suffix, and it is the LONGER
     * token. Length must not win.
     */
    public function testATownLeadingCountryPrefersThePrefix(): void
    {
        $this->seed('FR', ['Metz', 'Amiens', 'Mulhouse']);
        $this->club('FR', 'Metz Amiens');

        self::assertSame('Metz', $this->linkedTown('FR', 'Metz Amiens'));
    }

    /**
     * A SUFFIX-leading country: the affix happens to be another town's name.
     *
     * "Al Riyadh Hail" in Saudi Arabia — the town is Hail, and "Al Riyadh" is the affix. Riyadh
     * is the longer token and sits earlier in the string, so both length and prefix preference
     * get this wrong. THIS IS THE CASE THAT SHIPPED BROKEN.
     */
    public function testASuffixLeadingCountryPrefersTheSuffix(): void
    {
        $this->seed('SA', ['Riyadh', 'Hail', 'Zulfi']);
        $this->club('SA', 'Al Riyadh Hail');
        $this->club('SA', 'Al Riyadh Zulfi');

        self::assertSame('Hail', $this->linkedTown('SA', 'Al Riyadh Hail'));
        self::assertSame('Zulfi', $this->linkedTown('SA', 'Al Riyadh Zulfi'));
    }

    /** The ordinary suffix-leading case: the affix is not a town, so either rule would work. */
    public function testASuffixLeadingCountryWithAnOrdinaryAffix(): void
    {
        $this->seed('PL', ['Lodz', 'Gdynia', 'Warszawa']);
        $this->club('PL', 'Legia Lodz');

        self::assertSame('Lodz', $this->linkedTown('PL', 'Legia Lodz'));
    }

    /** A substring is not a token: Manchester contains Chester. */
    public function testASubstringNeverMatches(): void
    {
        $this->seed('EN', ['Manchester', 'Chester']);
        $this->club('EN', 'Manchester Wednesday');

        self::assertSame('Manchester', $this->linkedTown('EN', 'Manchester Wednesday'));
    }

    /** A compound town contains a shorter one; the longer positional match wins. */
    public function testACompoundTownBeatsTheShorterOneInsideIt(): void
    {
        $this->seed('BR', ['Aparecida de Goiania', 'Goiania']);
        $this->club('BR', 'Aparecida de Goiania AC');

        self::assertSame('Aparecida de Goiania', $this->linkedTown('BR', 'Aparecida de Goiania AC'));
    }

    /** A name following neither convention still resolves, via the fallback pass. */
    public function testAnUnconventionalNameFallsBackToAnyTokenMatch(): void
    {
        $this->seed('EN', ['Burnley']);
        // Neither prefix nor suffix — the shape a hand-edited or imported club can have.
        $this->club('EN', 'FC Burnley Reserves');

        self::assertSame('Burnley', $this->linkedTown('EN', 'FC Burnley Reserves'));
    }

    /** A club whose town simply is not in the catalogue stays unlinked rather than guessing. */
    public function testAClubWithNoMatchingTownIsLeftAlone(): void
    {
        $this->seed('EN', ['Burnley']);
        $this->club('EN', 'Capital FC');

        $result = $this->linker->link(['EN']);

        self::assertSame(1, $result['unmatched']);
        self::assertNull($this->linkedTown('EN', 'Capital FC'));
    }

    // ───────────────────────── helpers ─────────────────────────

    private function linkedTown(string $country, string $clubName): ?string
    {
        $this->linker->link([$country]);
        $this->em->clear();

        $town = $this->connection->fetchOne(
            'SELECT l.town FROM npc_club n LEFT JOIN location l ON l.id = n.location_id
              WHERE n.country = :c AND n.name = :n',
            ['c' => $country, 'n' => $clubName],
        );

        return $town === false || $town === null ? null : (string) $town;
    }

    /** @param list<string> $towns */
    private function seed(string $code, array $towns): void
    {
        $this->countries[] = $code;

        $country = $this->em->find(CountryProfile::class, $code);

        if ($country === null) {
            $country = new CountryProfile($code, 'Test ' . $code);
            $country->setStatus(CountryProfileStatus::LIVE);
            $this->em->persist($country);
        }

        foreach ($towns as $town) {
            $normalised = ClubNameNormalizer::normalize($town);

            $existing = $this->connection->fetchOne(
                'SELECT 1 FROM location WHERE country_code = :c AND town_normalised = :t',
                ['c' => $code, 't' => $normalised],
            );

            if ($existing !== false) {
                continue;
            }

            $location = new Location($country, $town, strtolower($code . '-' . $normalised), $normalised);
            $location->setRegion('R');
            $location->setPopulation(100_000);
            $location->setCitySize(CitySize::MEDIUM);

            $this->em->persist($location);
        }

        $this->em->flush();
    }

    private function club(string $country, string $name): void
    {
        $this->em->persist(new NpcClub(
            $name, $country, 1, 50, '#111111', '#eeeeee', 1_000_000, [],
            region: 'R', citySize: CitySize::MEDIUM, populationSize: 100_000, isCapital: false,
        ));

        $this->em->flush();
    }

    private function purge(): void
    {
        // Real country codes are used so suffixLeadsName() returns the genuine answer; the rows
        // are scoped to the exact club names and towns these tests create.
        $codes = "('FR','SA','PL','EN','BR','ZA','ZB')";

        $this->connection->executeStatement(
            "DELETE FROM npc_club WHERE country IN $codes AND name IN (
                'Metz Amiens','Al Riyadh Hail','Al Riyadh Zulfi','Legia Lodz',
                'Manchester Wednesday','Aparecida de Goiania AC','FC Burnley Reserves','Capital FC')",
        );
        $this->connection->executeStatement(
            "DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code IN ('ZA','ZB'))",
        );
        $this->connection->executeStatement("DELETE FROM location WHERE country_code IN ('ZA','ZB')");
        $this->connection->executeStatement("DELETE FROM country_profile WHERE code IN ('ZA','ZB')");
    }
}

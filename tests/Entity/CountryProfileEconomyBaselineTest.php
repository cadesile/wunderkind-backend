<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Location\CountryProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exactly one country may be the economy baseline, and the DATABASE is what guarantees it.
 *
 * WHY THIS IS NOT LEFT TO A SERVICE. Every country's economy factor is normalised against the
 * baseline row's own value, so a second baseline makes the whole country x tier chain ambiguous
 * — and the symptom is money that is quietly a bit wrong everywhere, which nobody traces back
 * to a flag on a country profile. A service-level check would also have to hold across the
 * admin form, a config import, a console command and a fixture, and the one path that forgets
 * is the one that breaks it.
 *
 * THE INDEX HAS TO BE PARTIAL, and that is the part worth a test rather than a comment: `false`
 * is the normal state for every row but one, so an unconditional unique index would refuse the
 * SECOND non-baseline country and make the table unusable. Both halves are asserted below —
 * that two baselines are refused, and that two non-baselines are fine.
 *
 * Driven through the real connection because a partial index is a database object; nothing
 * about it is visible from the mapping at runtime, and `doctrine:schema:create` is what puts it
 * on the test database.
 */
class CountryProfileEconomyBaselineTest extends KernelTestCase
{
    /** Codes this test inserts, so tearDown removes exactly those. */
    private const CODES = ['Y1', 'Y2', 'Y3'];

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    public function testASecondBaselineIsRefusedByTheDatabase(): void
    {
        $this->insert('Y1', isBaseline: true);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insert('Y2', isBaseline: true);
    }

    public function testPromotingASecondCountryToBaselineIsAlsoRefused(): void
    {
        // The realistic route: an operator ticks a new baseline without clearing the old one.
        // An INSERT guard alone would miss this.
        $this->insert('Y1', isBaseline: true);
        $this->insert('Y2');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement(
            "UPDATE country_profile SET is_economy_baseline = true WHERE code = 'Y2'",
        );
    }

    public function testManyNonBaselineCountriesAreFine(): void
    {
        // The half a non-partial index would break — and 25 of 26 real rows are in this state.
        foreach (self::CODES as $code) {
            $this->insert($code);
        }

        self::assertSame(
            count(self::CODES),
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM country_profile WHERE code IN (?) AND NOT is_economy_baseline',
                [self::CODES],
                [\Doctrine\DBAL\ArrayParameterType::STRING],
            ),
        );
    }

    public function testMovingTheBaselineWorksWhenTheOldOneIsClearedFirst(): void
    {
        // Changing the baseline must be POSSIBLE, not just guarded. Clearing then setting in
        // one statement is the pattern the admin screen will have to use.
        $this->insert('Y1', isBaseline: true);
        $this->insert('Y2');

        $this->connection->executeStatement(
            "UPDATE country_profile SET is_economy_baseline = (code = 'Y2') WHERE code IN ('Y1', 'Y2')",
        );

        self::assertSame(
            'Y2',
            $this->connection->fetchOne(
                "SELECT code FROM country_profile WHERE is_economy_baseline AND code IN ('Y1', 'Y2')",
            ),
        );
    }

    public function testANewRowDefaultsToNotBaselineAtParity(): void
    {
        // The inert state the migration ships. Asserted against the DATABASE defaults rather
        // than the entity's property initialisers, which is what a raw insert or a restore sees.
        $this->insert('Y1');

        $row = $this->connection->fetchAssociative(
            "SELECT is_economy_baseline, economy_factor_bp FROM country_profile WHERE code = 'Y1'",
        );

        self::assertFalse((bool) $row['is_economy_baseline']);
        self::assertSame(10000, (int) $row['economy_factor_bp']);
    }

    public function testTheEntityRoundTripsThroughDoctrine(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $profile = new CountryProfile('Y3', 'Testland');
        $profile->setIsEconomyBaseline(true);
        $profile->setEconomyFactorBp(4200);
        $em->persist($profile);
        $em->flush();
        $em->clear();

        $loaded = $em->find(CountryProfile::class, 'Y3');

        self::assertNotNull($loaded);
        self::assertTrue($loaded->isEconomyBaseline());
        self::assertSame(4200, $loaded->getEconomyFactorBp());
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /**
     * Raw INSERT with only the NOT NULL columns, so the DATABASE defaults are what fills the
     * two economy columns — which is the thing under test.
     */
    private function insert(string $code, bool $isBaseline = false): void
    {
        $sql = 'INSERT INTO country_profile (code, name, default_locale, distance_unit, status, league_tier_count, created_at, updated_at'
            . ($isBaseline ? ', is_economy_baseline' : '')
            . ") VALUES (:code, :name, 'en_GB', 'km', 'draft', 8, now(), now()"
            . ($isBaseline ? ', true' : '')
            . ')';

        $this->connection->executeStatement($sql, ['code' => $code, 'name' => 'Testland ' . $code]);
    }

    private function purge(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM country_profile WHERE code IN (?)',
            [self::CODES],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }
}

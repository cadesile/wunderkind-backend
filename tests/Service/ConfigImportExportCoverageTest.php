<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\GameConfig;
use App\Entity\PoolConfig;
use App\Entity\StarterConfig;
use App\Enum\ReputationTier;
use App\Service\ConfigImportExportService;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the config export/import contract.
 *
 * The service derives its field list from the entities by reflection, so the thing worth
 * testing is not "field X is handled" but that the derivation stays total: every mapped
 * column is covered, every deliberate exclusion is still real, and values survive a
 * round-trip with their type intact.
 */
class ConfigImportExportCoverageTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function configEntityProvider(): array
    {
        return [
            'GameConfig'    => [GameConfig::class],
            'StarterConfig' => [StarterConfig::class],
            'PoolConfig'    => [PoolConfig::class],
        ];
    }

    /**
     * The drift guard. A new #[ORM\Column] on a config entity must appear in the export, or
     * be explicitly denied — otherwise an admin's backup silently loses it on restore.
     *
     */
    #[DataProvider('configEntityProvider')]
    public function testEveryMappedColumnIsExported(string $class): void
    {
        $denied   = ConfigImportExportService::DENIED_PROPERTIES[$class] ?? [];
        $exported = ConfigImportExportService::exportEntity(new $class());

        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            if ($property->getAttributes(ORM\Column::class) === []
                || $property->getAttributes(ORM\Id::class) !== []
                || in_array($property->getName(), $denied, true)) {
                continue;
            }

            self::assertArrayHasKey(
                $property->getName(),
                $exported,
                sprintf(
                    'Config field %s::$%s is persisted but not exported. Add a getter/setter '
                    . 'pair, or add it to ConfigImportExportService::DENIED_PROPERTIES.',
                    (new \ReflectionClass($class))->getShortName(),
                    $property->getName(),
                ),
            );
        }
    }

    #[DataProvider('configEntityProvider')]
    public function testEveryExportedFieldCanBeImported(string $class): void
    {
        $entity = new $class();

        foreach (array_keys(ConfigImportExportService::exportEntity($entity)) as $name) {
            self::assertTrue(
                method_exists($entity, 'set' . ucfirst($name)),
                sprintf('Exported field "%s" has no setter, so it cannot be imported.', $name),
            );
        }
    }

    /**
     * A stale deny entry — left behind after a rename — would silently start exporting the
     * renamed field, secrets included.
     */
    public function testDenylistEntriesStillExist(): void
    {
        foreach (ConfigImportExportService::DENIED_PROPERTIES as $class => $properties) {
            foreach ($properties as $property) {
                self::assertTrue(
                    property_exists($class, $property),
                    sprintf('DENIED_PROPERTIES names %s::$%s, which no longer exists.', $class, $property),
                );
            }
        }
    }

    public function testSecretsAndRuntimeStateAreNeverExported(): void
    {
        $exported = ConfigImportExportService::exportEntity(new GameConfig());

        self::assertArrayNotHasKey('recaptchaSecretKey', $exported);
        self::assertArrayNotHasKey('recaptchaSiteKey', $exported);
        self::assertArrayNotHasKey('lastPostedStatCategory', $exported);
    }

    #[DataProvider('configEntityProvider')]
    public function testExportIsJsonSerializable(string $class): void
    {
        self::assertIsString(json_encode(
            ConfigImportExportService::exportEntity(new $class()),
            JSON_THROW_ON_ERROR,
        ));
    }

    #[DataProvider('configEntityProvider')]
    public function testDefaultsRoundTripUnchanged(string $class): void
    {
        $source   = new $class();
        $exported = ConfigImportExportService::exportEntity($source);

        $target = new $class();
        self::assertSame([], ConfigImportExportService::applyEntity($target, $exported));
        self::assertEquals($exported, ConfigImportExportService::exportEntity($target));
    }

    /** One representative of every type the coercion has to handle. */
    public function testEachScalarTypeSurvivesImport(): void
    {
        $game = new GameConfig();
        ConfigImportExportService::applyEntity($game, [
            'retirementMinAge'   => 33,          // int
            'baseInjuryProbability' => 0.42,     // float
            'androidDownloadUrl' => 'https://example.test/app', // string
            'maxSponsorsByTier'  => ['1' => 9],  // json
        ]);

        self::assertSame(33, $game->getRetirementMinAge());
        self::assertSame(0.42, $game->getBaseInjuryProbability());
        self::assertSame('https://example.test/app', $game->getAndroidDownloadUrl());
        self::assertSame(['1' => 9], $game->getMaxSponsorsByTier());

        $starter = new StarterConfig();
        ConfigImportExportService::applyEntity($starter, ['starterReputationTier' => 'elite']);
        self::assertSame(ReputationTier::ELITE, $starter->getStarterReputationTier());
    }

    /**
     * The service used isset(), under which a boolean could only ever be imported as true
     * and a 0 was skipped entirely — so turning a flag off was impossible via import.
     */
    public function testFalseAndZeroAreApplied(): void
    {
        $game = new GameConfig();
        $game->setDebugLoggingEnabled(true);

        ConfigImportExportService::applyEntity($game, [
            'debugLoggingEnabled' => false,
            'baseXP'              => 0,
        ]);

        self::assertFalse($game->isDebugLoggingEnabled());
        self::assertSame(0, $game->getBaseXP());
    }

    /**
     * One malformed value must not discard the rest of the file — the old importer threw on
     * the first bad enum and abandoned every field after it.
     */
    public function testBadValueIsReportedWithoutBlockingOtherFields(): void
    {
        $starter = new StarterConfig();

        $errors = ConfigImportExportService::applyEntity($starter, [
            'starterReputationTier' => 'nonsense',
            'startingBalance'       => 12_345,
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('starterReputationTier', $errors[0]);
        self::assertStringContainsString('nonsense', $errors[0]);
        self::assertSame(12_345, $starter->getStartingBalance());
    }

    /**
     * The economy ratio tables and the tier-1 ticket base survive an export/import cycle.
     *
     * The coverage test above is reflection-total and already fails if one of these is not
     * exported at all. This pins the half of the contract reflection cannot see: that the
     * VALUES come back intact rather than normalised into something else on the way in.
     * A ratio table that round-trips as eight 10000s would restore a backup as "inert" while
     * reporting success — the quietest possible way to lose an authored economy.
     */
    public function testTheEconomyRatioTablesRoundTripWithTheirValues(): void
    {
        $source = (new GameConfig())
            ->setEconomySponsorTierRatioBp([10000, 5000, 2500, 1500, 1000, 700, 500, 400])
            ->setEconomyTicketPriceTierRatioBp([10000, 8000, 6500, 5000, 4000, 3200, 2600, 2200])
            ->setEconomyTicketPriceTier1Pence(4_000);

        $exported = ConfigImportExportService::exportEntity($source);

        self::assertSame([10000, 5000, 2500, 1500, 1000, 700, 500, 400], $exported['economySponsorTierRatioBp']);
        self::assertSame([10000, 8000, 6500, 5000, 4000, 3200, 2600, 2200], $exported['economyTicketPriceTierRatioBp']);
        self::assertSame(4_000, $exported['economyTicketPriceTier1Pence']);

        $target = new GameConfig();
        self::assertSame([], ConfigImportExportService::applyEntity($target, $exported));

        self::assertSame([10000, 5000, 2500, 1500, 1000, 700, 500, 400], $target->getEconomySponsorTierRatioBp());
        self::assertSame([10000, 8000, 6500, 5000, 4000, 3200, 2600, 2200], $target->getEconomyTicketPriceTierRatioBp());
        self::assertSame(4_000, $target->getEconomyTicketPriceTier1Pence());
    }

    /**
     * The league-payout ladders, which are PARTS PER MILLION and therefore the pair most likely
     * to be silently halved by a normaliser written for basis points.
     *
     * The failure this guards is the same one as above and worse: a config export is the ONLY
     * record of an authored prize ladder — the per-league columns are cleared by
     * Version20261116181000 and `down()` restores from a stash table that a fresh box will not
     * have. A round trip that quietly returned parity would restore a backup as "every division
     * paid tier 1's prize", which is £100,000,000 to a tier-8 champion.
     */
    public function testTheLeaguePayoutLaddersRoundTripWithTheirValues(): void
    {
        $ladder = [1000000, 309000, 91500, 51200, 18200, 6020, 1500, 424];

        $source = (new GameConfig())
            ->setEconomyPrizeTierRatioPpm($ladder)
            ->setEconomyPositionPotTierRatioPpm($ladder)
            ->setEconomyLeaguePrizeTier1Pence(10_000_000_000)
            ->setEconomyLeaguePositionPotTier1Pence(10_000_000_000);

        $exported = ConfigImportExportService::exportEntity($source);

        self::assertSame($ladder, $exported['economyPrizeTierRatioPpm']);
        self::assertSame($ladder, $exported['economyPositionPotTierRatioPpm']);
        self::assertSame(10_000_000_000, $exported['economyLeaguePrizeTier1Pence']);
        self::assertSame(10_000_000_000, $exported['economyLeaguePositionPotTier1Pence']);

        $target = new GameConfig();
        self::assertSame([], ConfigImportExportService::applyEntity($target, $exported));

        self::assertSame($ladder, $target->getEconomyPrizeTierRatioPpm());
        self::assertSame($ladder, $target->getEconomyPositionPotTierRatioPpm());
        self::assertSame(10_000_000_000, $target->getEconomyLeaguePrizeTier1Pence());
        self::assertSame(10_000_000_000, $target->getEconomyLeaguePositionPotTier1Pence());

        // AND THE UNIT IS NOT CONFUSED WITH THE BASIS-POINT TABLES on the same entity. 424 ppm
        // read as 424 bp is 100x the money.
        self::assertNotSame($target->getEconomyPrizeTierRatioPpm(), $target->getEconomySponsorTierRatioBp());
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $pool = new PoolConfig();

        self::assertSame([], ConfigImportExportService::applyEntity($pool, [
            'aFieldRemovedThreeVersionsAgo' => 1,
        ]));
    }
}

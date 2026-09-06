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

    public function testUnknownKeysAreIgnored(): void
    {
        $pool = new PoolConfig();

        self::assertSame([], ConfigImportExportService::applyEntity($pool, [
            'aFieldRemovedThreeVersionsAgo' => 1,
        ]));
    }
}

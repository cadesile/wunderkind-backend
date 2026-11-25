<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Container\ContainerInterface;

/**
 * What the ORM mapping still wants from the database it is pointed at.
 *
 * One implementation, two callers, deliberately: `SchemaIsInSyncTest` asserts the answer is
 * empty, and `tests/bootstrap.php` refuses to start a run when it is not. They must agree —
 * if the guard's idea of "in sync" were looser than the test's, a drifted database would be
 * waved through and the suite would fail 200 tests later for reasons that look like code.
 *
 * TWO EXCLUSIONS, both tables that exist without being entities, so SchemaTool proposes
 * dropping them. The console command never shows these because a bundle registers them in the
 * connection's schema-asset filter at runtime, and a direct SchemaTool call does not go through
 * that filter. See SchemaIsInSyncTest's docblock for the full reasoning, including why the
 * migrations table is read from configuration and `messenger_messages` is hardcoded.
 */
final class TestSchemaDrift
{
    /** The Doctrine transport's queue table, created lazily on first dispatch. */
    private const MESSENGER_TABLE = 'messenger_messages';

    /**
     * @return list<string> the outstanding DDL, empty when the mapping and the database agree
     */
    public static function outstandingSql(EntityManagerInterface $em, ContainerInterface $container): array
    {
        $metadata = $em->getMetadataFactory()->getAllMetadata();

        if ($metadata === []) {
            // Not "in sync" — the mapping is not loading at all, and reporting no drift here
            // would hide that behind a green schema check.
            return ['-- no entity metadata found: the mapping is not loading'];
        }

        $ignored = [self::migrationsTableName($container), self::MESSENGER_TABLE];

        return array_values(array_filter(
            (new SchemaTool($em))->getUpdateSchemaSql($metadata),
            static function (string $statement) use ($ignored): bool {
                foreach ($ignored as $table) {
                    if (str_contains($statement, $table)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /** The migrations bundle's storage table, per configuration rather than hardcoded. */
    private static function migrationsTableName(ContainerInterface $container): string
    {
        if (!$container->has(DependencyFactory::class)) {
            return 'doctrine_migration_versions';
        }

        /** @var DependencyFactory $factory */
        $factory = $container->get(DependencyFactory::class);

        return $factory->getConfiguration()->getMetadataStorageConfiguration()?->getTableName()
            ?? 'doctrine_migration_versions';
    }
}

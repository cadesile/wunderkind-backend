<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The ORM mapping and the migrated database must describe the same schema.
 *
 * This is the automated form of the project rule that `doctrine:migrations:diff` reports no
 * changes and `doctrine:schema:update --dump-sql` reports nothing to update — stated in
 * .context/stages/03_data/output/migrations.md as "if a diff ever comes back noisy again,
 * that is a signal, not background noise". Until now that rule was only ever checked by a
 * human remembering to run the command.
 *
 * It is worth a test because the location/land-plot work introduces several PARTIAL unique
 * indexes, and those are the one thing in this schema that silently drifts. A partial unique
 * must be declared on the entity as
 *
 *     #[ORM\UniqueConstraint(..., options: ['where' => '<predicate>'])]
 *
 * with the predicate spelled exactly as `pg_get_expr(indpred, indrelid)` reports it. Get the
 * spelling even slightly wrong and two things break quietly: `doctrine:schema:create` stops
 * building the index on a fresh `wunderkind_test`, so the constraint a feature depends on is
 * absent in tests while present in production; and every later `migrations:diff` proposes
 * dropping and recreating it, which trains everyone to ignore diff output.
 *
 * CHECK constraints are deliberately NOT covered — Doctrine cannot map them, so they live in
 * raw migration SQL only (see Version20261103120000 and LocationRival). SchemaTool does not
 * see them either way, which is exactly why they are safe to keep there.
 *
 * One exclusion, and only one: the migrations bundle's own storage table. It is not an
 * entity, so SchemaTool proposes dropping it. The console command never shows that because
 * DoctrineMigrationsBundle registers the table in the connection's schema-asset filter at
 * runtime, and a direct SchemaTool call does not go through that filter. The name is read
 * back from the migrations configuration rather than hardcoded, so renaming it in
 * doctrine_migrations.yaml does not quietly turn this test into a no-op.
 *
 * If this fails, the message prints the SQL the ORM thinks is still outstanding. Either the
 * migration is missing, or an entity attribute does not match what the migration built. On
 * the TEST database, remember it is built by doctrine:schema:create from entity metadata and
 * is NOT migration-tracked — a new migration never reaches it. See
 * .context/stages/01_overview/output/environment.md.
 */
class SchemaIsInSyncTest extends KernelTestCase
{
    public function testOrmMappingMatchesTheMigratedDatabase(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $metadata = $em->getMetadataFactory()->getAllMetadata();
        self::assertNotEmpty($metadata, 'No entity metadata found — the mapping is not loading.');

        $sql = (new SchemaTool($em))->getUpdateSchemaSql($metadata);

        $migrationsTable = $this->migrationsTableName();
        $outstanding     = array_values(array_filter(
            $sql,
            static fn (string $statement): bool => !str_contains($statement, $migrationsTable),
        ));

        self::assertSame(
            [],
            $outstanding,
            "The ORM mapping and the database disagree. Outstanding SQL:\n  "
            . implode("\n  ", $outstanding),
        );
    }

    /** The migrations bundle's storage table, per configuration — see the class docblock. */
    private function migrationsTableName(): string
    {
        $container = self::getContainer();

        if (!$container->has(DependencyFactory::class)) {
            return 'doctrine_migration_versions';
        }

        /** @var DependencyFactory $factory */
        $factory = $container->get(DependencyFactory::class);

        return $factory->getConfiguration()->getMetadataStorageConfiguration()?->getTableName()
            ?? 'doctrine_migration_versions';
    }
}

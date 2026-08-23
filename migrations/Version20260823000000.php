<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give Staff and Scout the same 8-spoke Personality Matrix Player already carries.
 *
 * Columns mirror the player embeddable exactly — same names, same SMALLINT type,
 * same default of 10 (the mid-point of the 1–20 scale). That default doubles as
 * the "not yet generated" marker PersonalityLifecycleSubscriber reads, so
 * pre-existing rows are backfilled lazily rather than by this migration.
 */
final class Version20260823000000 extends AbstractMigration
{
    private const TRAITS = [
        'determination', 'professionalism', 'ambition', 'loyalty',
        'adaptability', 'pressure', 'temperament', 'consistency',
    ];

    public function getDescription(): string
    {
        return 'Add the personality matrix columns to staff and scout.';
    }

    public function up(Schema $schema): void
    {
        foreach (['staff', 'scout'] as $table) {
            foreach (self::TRAITS as $trait) {
                $this->addSql("ALTER TABLE {$table} ADD personality_{$trait} SMALLINT DEFAULT 10 NOT NULL");
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['staff', 'scout'] as $table) {
            foreach (self::TRAITS as $trait) {
                $this->addSql("ALTER TABLE {$table} DROP personality_{$trait}");
            }
        }
    }
}

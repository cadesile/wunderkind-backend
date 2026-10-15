<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * First-team age floor on pool_config.
 *
 * Fixes starter packs (and NPC squads) being filled with children: one global age range,
 * playerAgeMin/Max, governed both what the pool CONTAINS and who a senior squad took out
 * of it, and the pool has to span youth ages so it can supply GET /api/players/youth.
 * StarterPackService drew on ability/position/nationality with no age condition at all.
 *
 * Default 19 rather than 18 is deliberate: the backend sends only dateOfBirth and the
 * client derives age against the in-game clock, which runs slightly behind real time, so a
 * backend-exact 18 can read as 17 on the device.
 *
 * Additive with a default, so the existing config row picks it up with no data migration.
 * Nothing is backfilled - already-issued squads keep the players they were given.
 */
final class Version20261015213517 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add first-team age floor to pool_config (starter packs and NPC squads).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_config ADD player_first_team_age_min INT DEFAULT 19 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_config DROP player_first_team_age_min');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Record how deep a country's league pyramid is meant to be.
 *
 * "Generate League Structure" can now build 1-8 tiers rather than always 8, and NPC quality
 * is interpolated against the country's own depth so that the bottom tier of a short
 * pyramid is a bottom-of-the-pyramid division rather than a mid-table one.
 *
 * DEFAULT 8 AND NOT NULL, because every country that exists when this runs genuinely has
 * eight tiers — so the backfill is the default, and no country is left describing itself
 * as having no pyramid at all.
 *
 * This is the declared INTENT, deliberately separate from COUNT(league). The count is
 * briefly wrong while a structure is being generated, and rescaling a half-built country's
 * clubs against it would produce quietly wrong reputations and balances.
 */
final class Version20261106230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add country_profile.league_tier_count (1-8, default 8).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_profile ADD league_tier_count SMALLINT DEFAULT 8 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_profile DROP league_tier_count');
    }
}

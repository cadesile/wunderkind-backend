<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add slug + polarity to player_archetype and rename trait_mapping to trait_weights — curated 20-archetype catalogue';
    }

    public function up(Schema $schema): void
    {
        // The 30-archetype catalogue is replaced wholesale by app:seed-archetypes, and the new
        // columns are NOT NULL, so the legacy rows have to go first. Nothing FKs to this table.
        // Deploy runs app:seed-archetypes immediately after migrating.
        $this->addSql('DELETE FROM player_archetype');

        $this->addSql('ALTER TABLE player_archetype RENAME COLUMN trait_mapping TO trait_weights');
        $this->addSql('ALTER TABLE player_archetype ADD slug VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE player_archetype ADD polarity VARCHAR(16) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ARCHETYPE_SLUG ON player_archetype (slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM player_archetype');

        $this->addSql('DROP INDEX UNIQ_ARCHETYPE_SLUG');
        $this->addSql('ALTER TABLE player_archetype DROP polarity');
        $this->addSql('ALTER TABLE player_archetype DROP slug');
        $this->addSql('ALTER TABLE player_archetype RENAME COLUMN trait_weights TO trait_mapping');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add season_ticket_holder_percent to game_config — percentage of matchday
 * attendance made up of season-ticket holders (Club Development). Default 60.
 */
final class Version20260719000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add season_ticket_holder_percent to game_config (Club Development, default 60)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD season_ticket_holder_percent SMALLINT DEFAULT 60 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP season_ticket_holder_percent');
    }
}

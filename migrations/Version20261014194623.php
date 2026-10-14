<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index for the youth pool draw (GET /api/players/youth).
 *
 * PlayerRepository::findYouthStratum() filters a date_of_birth RANGE plus a potential band,
 * optionally narrowed by nationality, over a pool that serves the whole league pyramid.
 * Before this, `player` carried only its primary key and an agent_id index, so every youth
 * draw sequentially scanned the entire table.
 */
final class Version20261014194623 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idx_player_youth_draw for the youth pool draw endpoint.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_player_youth_draw ON player (date_of_birth, potential, nationality)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_player_youth_draw');
    }
}

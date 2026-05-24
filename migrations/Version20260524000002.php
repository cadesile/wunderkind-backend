<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add potential_overshoot_max and potential_decay_rate to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD potential_overshoot_max DOUBLE PRECISION DEFAULT 0.05 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD potential_decay_rate DOUBLE PRECISION DEFAULT 0.5 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP potential_overshoot_max');
        $this->addSql('ALTER TABLE game_config DROP potential_decay_rate');
    }
}

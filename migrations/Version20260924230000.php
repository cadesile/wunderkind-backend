<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add player.secondary_position (nullable) for a player\'s optional secondary position';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player ADD secondary_position VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP secondary_position');
    }
}

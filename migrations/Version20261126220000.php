<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * leaderboard_award_definition.trophy: the trophy sprite config the app's Rankings dashboard
 * draws for an award. Nullable, so every existing definition falls back to the default cup.
 */
final class Version20261126220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add leaderboard_award_definition.trophy (trophy sprite config for the Rankings dashboard).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leaderboard_award_definition ADD trophy JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leaderboard_award_definition DROP trophy');
    }
}

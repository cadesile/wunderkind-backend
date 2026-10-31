<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The academy scholarship wage, in PENCE — the flat weekly wage for a player SIGNED below the
 * first-team age. The app already reads it and falls back to its own 25000 when absent, so an
 * unmigrated database keeps today's behaviour rather than pricing scholars at 0.
 */
final class Version20261031214540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game_config.youth_scholarship_wage_pence (academy scholarship wage, in pence).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD youth_scholarship_wage_pence INT DEFAULT 25000 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP youth_scholarship_wage_pence');
    }
}

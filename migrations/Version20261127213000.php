<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove Starter config "Club starting balance" (Courtney, 2026-11-27). A new club opens with
 * what its owner funds it with at the FUND step, so the club kitty setting is dead.
 * GET /api/starter-config keeps sending startingBalance: 0 for older builds.
 */
final class Version20261127213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop starter_config.starting_balance (club kitty setting removed)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE starter_config DROP starting_balance');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE starter_config ADD starting_balance INT DEFAULT 5000000 NOT NULL');
    }
}

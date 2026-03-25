<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed the single starter_config row with all default values.
 * Uses ON DUPLICATE KEY UPDATE id = id so re-running is safe.
 */
final class Version20260325234056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed default StarterConfig row (id = 1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO starter_config (id, starting_balance, starter_player_count, starter_coach_count, starter_scout_count, starter_sponsor_tier)
             VALUES (1, 5000000, 5, 1, 1, \'SMALL\')
             ON DUPLICATE KEY UPDATE id = id'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM starter_config WHERE id = 1');
    }
}

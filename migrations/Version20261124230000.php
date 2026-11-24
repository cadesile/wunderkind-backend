<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two player contract renewal dials.
 *
 * - game_config.player_contract_max_years (default 5): the longest a player's contract may run.
 *   A renewal adds years onto what is left, so the app disables any length that would take the
 *   remaining contract past this.
 * - game_config.player_contract_renewal_cooldown_weeks (default 8): weeks after a renewal before
 *   the same player can be offered another one.
 */
final class Version20261124230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game_config.player_contract_max_years and player_contract_renewal_cooldown_weeks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD player_contract_max_years INT DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD player_contract_renewal_cooldown_weeks INT DEFAULT 8 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP player_contract_max_years');
        $this->addSql('ALTER TABLE game_config DROP player_contract_renewal_cooldown_weeks');
    }
}

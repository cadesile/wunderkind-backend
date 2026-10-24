<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets the in-app debug log panel be switched on for ONE account, without turning it on
 * for everyone.
 *
 * The only switch until now was the global GameConfig::$debugLoggingEnabled, so debugging a
 * single player's device meant exposing the panel (which records every API request and
 * response) to the whole player base for the duration.
 *
 * Defaults to false for every existing row, so this changes nothing on its own. The flag is
 * additive only: GameConfigController serves `global || per-user`, so it can turn the panel
 * on for an account and can never turn it off while the global switch is on.
 */
final class Version20261024222441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.debug_logging_enabled, a per-account override for the in-app debug log panel.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD debug_logging_enabled BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP debug_logging_enabled');
    }
}

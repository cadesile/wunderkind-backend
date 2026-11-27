<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inactive account cleanup (Courtney, 2026-11-27): app:users:prune-inactive deletes accounts
 * that never played (no club, ledger, plot or sale, ever) after 90 days unseen and a 14-day
 * warning email.
 *
 * The switch is OFF by default, so deploying this deletes nothing anywhere. Dev and prod have
 * separate databases, so the same baked-in crontab can be dry on one and live on the other.
 */
final class Version20261127210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inactive account cleanup: game_config switch + thresholds, inactive_account_warning table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD inactive_account_prune_mode VARCHAR(10) DEFAULT 'off' NOT NULL");
        $this->addSql('ALTER TABLE game_config ADD inactive_account_prune_days INT DEFAULT 90 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD inactive_account_prune_warning_days INT DEFAULT 14 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD inactive_account_prune_max_per_run INT DEFAULT 50 NOT NULL');
        $this->addSql("ALTER TABLE game_config ADD inactive_account_prune_protected_emails TEXT DEFAULT '' NOT NULL");

        $this->addSql(<<<'SQL'
            CREATE TABLE inactive_account_warning (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                warned_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                delete_after TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_inactive_account_warning_user ON inactive_account_warning (user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE inactive_account_warning
                ADD CONSTRAINT FK_INACTIVE_ACCOUNT_WARNING_USER
                FOREIGN KEY (user_id) REFERENCES "user" (id)
                ON DELETE CASCADE NOT DEFERRABLE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inactive_account_warning');
        $this->addSql('ALTER TABLE game_config DROP inactive_account_prune_mode');
        $this->addSql('ALTER TABLE game_config DROP inactive_account_prune_days');
        $this->addSql('ALTER TABLE game_config DROP inactive_account_prune_warning_days');
        $this->addSql('ALTER TABLE game_config DROP inactive_account_prune_max_per_run');
        $this->addSql('ALTER TABLE game_config DROP inactive_account_prune_protected_emails');
    }
}

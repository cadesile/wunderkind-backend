<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `notification_log` — an audit record of every push-related Messenger message actually
 * processed (success or failure), written by NotificationLoggingSubscriber. No change to the
 * `failed` Messenger transport enabled alongside this in config/packages/messenger.yaml — it
 * reuses the existing `messenger_messages` table (Doctrine transports differentiate by
 * `queue_name`, not table).
 *
 * `doctrine:migrations:diff` also proposed the same unrelated pre-existing schema drift
 * (hand-added partial unique indexes, DROP DEFAULT/rename noise) documented in earlier
 * migrations in this file — none of that belongs here, so only the new table is kept.
 */
final class Version20260920075918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notification_log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_log (id UUID NOT NULL, status VARCHAR(20) NOT NULL, message_type VARCHAR(100) NOT NULL, summary TEXT NOT NULL, detail_json JSON NOT NULL, error_message VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_notification_log_created_at ON notification_log (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_log');
    }
}

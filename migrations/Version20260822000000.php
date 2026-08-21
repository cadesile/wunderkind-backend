<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Server-driven messaging: operator-authored announcements targeted at club cohorts.
 *
 * Targeting is evaluated against a Club — every cohort axis (reputation, league tier, country,
 * week) lives there. Delivery state is recorded against the User, so a person sees an
 * announcement once however many clubs they start, and guests and registered accounts (both
 * plain User rows) behave identically.
 */
final class Version20260822000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_message, audience_group, audience_group_member and message_delivery tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE audience_group (
                id UUID NOT NULL,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                criteria_type VARCHAR(255) DEFAULT 'manual' NOT NULL,
                criteria_payload JSON DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_audience_group_slug ON audience_group (slug)');

        $this->addSql(<<<'SQL'
            CREATE TABLE admin_message (
                id UUID NOT NULL,
                title VARCHAR(150) NOT NULL,
                body_html TEXT NOT NULL,
                target_type VARCHAR(255) DEFAULT 'broadcast' NOT NULL,
                priority SMALLINT DEFAULT 2 NOT NULL,
                display_type VARCHAR(255) DEFAULT 'inbox_item' NOT NULL,
                valid_from TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                valid_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                is_active BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_by_id UUID DEFAULT NULL,
                target_club_id UUID DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_7281B2F6B03A8386 ON admin_message (created_by_id)');
        $this->addSql('CREATE INDEX IDX_7281B2F6AA71B359 ON admin_message (target_club_id)');
        // Leading predicate of every poll.
        $this->addSql('CREATE INDEX idx_admin_message_active_window ON admin_message (is_active, valid_from)');

        $this->addSql(<<<'SQL'
            CREATE TABLE admin_message_audience_group (
                admin_message_id UUID NOT NULL,
                audience_group_id UUID NOT NULL,
                PRIMARY KEY (admin_message_id, audience_group_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_DFDE504F9B44B5AC ON admin_message_audience_group (admin_message_id)');
        $this->addSql('CREATE INDEX IDX_DFDE504F688032C9 ON admin_message_audience_group (audience_group_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE audience_group_member (
                id UUID NOT NULL,
                club_id UUID NOT NULL,
                group_id UUID NOT NULL,
                joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_F76BEA8B61190A32 ON audience_group_member (club_id)');
        $this->addSql('CREATE INDEX IDX_F76BEA8BFE54D947 ON audience_group_member (group_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_audience_member ON audience_group_member (club_id, group_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE message_delivery (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                message_id UUID NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                displayed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                status VARCHAR(255) DEFAULT 'pending' NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_3EA72801A76ED395 ON message_delivery (user_id)');
        $this->addSql('CREATE INDEX IDX_3EA72801537A1329 ON message_delivery (message_id)');
        // Not merely hygiene: AdminMessageService::acknowledge() upserts ON CONFLICT against
        // this constraint, which is what makes a repeated ack idempotent.
        $this->addSql('CREATE UNIQUE INDEX uq_message_delivery ON message_delivery (user_id, message_id)');

        // SET NULL on the author: removing an admin account must not delete published messages.
        $this->addSql('ALTER TABLE admin_message ADD CONSTRAINT FK_7281B2F6B03A8386 FOREIGN KEY (created_by_id) REFERENCES admin (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE admin_message ADD CONSTRAINT FK_7281B2F6AA71B359 FOREIGN KEY (target_club_id) REFERENCES club (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE admin_message_audience_group ADD CONSTRAINT FK_DFDE504F9B44B5AC FOREIGN KEY (admin_message_id) REFERENCES admin_message (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE admin_message_audience_group ADD CONSTRAINT FK_DFDE504F688032C9 FOREIGN KEY (audience_group_id) REFERENCES audience_group (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE audience_group_member ADD CONSTRAINT FK_F76BEA8B61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE audience_group_member ADD CONSTRAINT FK_F76BEA8BFE54D947 FOREIGN KEY (group_id) REFERENCES audience_group (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE message_delivery ADD CONSTRAINT FK_3EA72801A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE message_delivery ADD CONSTRAINT FK_3EA72801537A1329 FOREIGN KEY (message_id) REFERENCES admin_message (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_delivery DROP CONSTRAINT FK_3EA72801537A1329');
        $this->addSql('ALTER TABLE message_delivery DROP CONSTRAINT FK_3EA72801A76ED395');
        $this->addSql('ALTER TABLE audience_group_member DROP CONSTRAINT FK_F76BEA8BFE54D947');
        $this->addSql('ALTER TABLE audience_group_member DROP CONSTRAINT FK_F76BEA8B61190A32');
        $this->addSql('ALTER TABLE admin_message_audience_group DROP CONSTRAINT FK_DFDE504F688032C9');
        $this->addSql('ALTER TABLE admin_message_audience_group DROP CONSTRAINT FK_DFDE504F9B44B5AC');
        $this->addSql('ALTER TABLE admin_message DROP CONSTRAINT FK_7281B2F6AA71B359');
        $this->addSql('ALTER TABLE admin_message DROP CONSTRAINT FK_7281B2F6B03A8386');

        $this->addSql('DROP TABLE message_delivery');
        $this->addSql('DROP TABLE audience_group_member');
        $this->addSql('DROP TABLE admin_message_audience_group');
        $this->addSql('DROP TABLE admin_message');
        $this->addSql('DROP TABLE audience_group');
    }
}

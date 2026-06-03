<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260603000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email verification: is_verified + verified_at to user, create email_verification table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN is_verified BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE "user" ADD COLUMN verified_at TIMESTAMPTZ DEFAULT NULL');

        $this->addSql('CREATE TABLE email_verification (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            code VARCHAR(6) NOT NULL,
            expires_at TIMESTAMPTZ NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            verified_at TIMESTAMPTZ DEFAULT NULL,
            created_at TIMESTAMPTZ NOT NULL,
            PRIMARY KEY (id),
            CONSTRAINT fk_email_verification_user
                FOREIGN KEY (user_id) REFERENCES "user"(id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE INDEX idx_email_verification_user
            ON email_verification (user_id, verified_at, expires_at)');

        $this->addSql("COMMENT ON COLUMN email_verification.expires_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN email_verification.verified_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN email_verification.created_at IS '(DC2Type:datetimetz_immutable)'");
        $this->addSql("COMMENT ON COLUMN \"user\".verified_at IS '(DC2Type:datetimetz_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_verification');
        $this->addSql('ALTER TABLE "user" DROP COLUMN is_verified');
        $this->addSql('ALTER TABLE "user" DROP COLUMN verified_at');
    }
}

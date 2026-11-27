<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin user Notify (Courtney, 2026-11-27): admin_message.target_user_id for the new
 * MessageTargetType::DIRECT_USER, so an operator notice reaches an account whatever club it
 * plays next, including after go-live when its old clubs have been sold.
 */
final class Version20261127200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin message: direct user targeting (target_user_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_message ADD target_user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_message ADD CONSTRAINT FK_7281B2F66C066AFE FOREIGN KEY (target_user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_7281B2F66C066AFE ON admin_message (target_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_message DROP CONSTRAINT FK_7281B2F66C066AFE');
        $this->addSql('DROP INDEX IDX_7281B2F66C066AFE');
        $this->addSql('ALTER TABLE admin_message DROP target_user_id');
    }
}

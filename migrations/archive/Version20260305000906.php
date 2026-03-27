<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260305000906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Market lifecycle: add assigned_at to player/staff/sponsor/investor; transfer.player_id ON DELETE CASCADE; performance indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investor ADD assigned_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD assigned_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE sponsor ADD assigned_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE staff ADD assigned_at DATETIME DEFAULT NULL');

        // Indexes for cleanup query performance
        $this->addSql('CREATE INDEX idx_player_assigned_at ON player (assigned_at)');
        $this->addSql('CREATE INDEX idx_staff_assigned_at ON staff (assigned_at)');
        $this->addSql('CREATE INDEX idx_sponsor_assigned_at ON sponsor (assigned_at)');
        $this->addSql('CREATE INDEX idx_investor_assigned_at ON investor (assigned_at)');

        // Transfer cascade so age-out player deletion removes their transfer history
        $this->addSql('ALTER TABLE transfer DROP FOREIGN KEY `FK_4034A3C099E6F5DF`');
        $this->addSql('ALTER TABLE transfer ADD CONSTRAINT FK_4034A3C099E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_player_assigned_at ON player');
        $this->addSql('DROP INDEX idx_staff_assigned_at ON staff');
        $this->addSql('DROP INDEX idx_sponsor_assigned_at ON sponsor');
        $this->addSql('DROP INDEX idx_investor_assigned_at ON investor');
        $this->addSql('ALTER TABLE investor DROP assigned_at');
        $this->addSql('ALTER TABLE player DROP assigned_at');
        $this->addSql('ALTER TABLE sponsor DROP assigned_at');
        $this->addSql('ALTER TABLE staff DROP assigned_at');
        $this->addSql('ALTER TABLE transfer DROP FOREIGN KEY FK_4034A3C099E6F5DF');
        $this->addSql('ALTER TABLE transfer ADD CONSTRAINT `FK_4034A3C099E6F5DF` FOREIGN KEY (player_id) REFERENCES player (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
    }
}

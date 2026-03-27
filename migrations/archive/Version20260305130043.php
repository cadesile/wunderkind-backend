<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260305130043 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transfer entity: nullable player (SET NULL), add leaderboard fields + performance indexes';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transfer DROP FOREIGN KEY `FK_4034A3C099E6F5DF`');
        $this->addSql('ALTER TABLE transfer ADD net_proceeds INT DEFAULT 0 NOT NULL, ADD development_points INT DEFAULT 0 NOT NULL, ADD reputation_gained INT DEFAULT 0 NOT NULL, ADD buying_club VARCHAR(100) DEFAULT NULL, CHANGE player_id player_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE transfer ADD CONSTRAINT FK_4034A3C099E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_transfer_academy_occurred ON transfer (academy_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_transfer_net_proceeds ON transfer (net_proceeds)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_transfer_academy_occurred ON transfer');
        $this->addSql('DROP INDEX idx_transfer_net_proceeds ON transfer');
        $this->addSql('ALTER TABLE transfer DROP FOREIGN KEY FK_4034A3C099E6F5DF');
        $this->addSql('ALTER TABLE transfer DROP net_proceeds, DROP development_points, DROP reputation_gained, DROP buying_club, CHANGE player_id player_id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE transfer ADD CONSTRAINT `FK_4034A3C099E6F5DF` FOREIGN KEY (player_id) REFERENCES player (id) ON UPDATE NO ACTION ON DELETE CASCADE');
    }
}

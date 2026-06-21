<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260621135817 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Data cleanup: delete any pool entities that were assigned to clubs
        // (frontend already has their snapshots; these are safe to remove)
        $this->addSql('DELETE FROM guardian WHERE player_id IN (SELECT id FROM player WHERE club_id IS NOT NULL)');
        $this->addSql('DELETE FROM player WHERE club_id IS NOT NULL');
        $this->addSql('DELETE FROM staff WHERE club_id IS NOT NULL');

        $this->addSql('ALTER TABLE player DROP CONSTRAINT fk_98197a656d55acab');
        $this->addSql('DROP INDEX idx_player_club');
        $this->addSql('ALTER TABLE player DROP age_out_warning_issued');
        $this->addSql('ALTER TABLE player DROP forced_sale_executed');
        $this->addSql('ALTER TABLE player DROP forced_sale_week');
        $this->addSql('ALTER TABLE player DROP club_id');
        $this->addSql('ALTER TABLE staff DROP CONSTRAINT fk_426ef3926d55acab');
        $this->addSql('DROP INDEX idx_staff_club');
        $this->addSql('ALTER TABLE staff DROP club_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player ADD age_out_warning_issued BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE player ADD forced_sale_executed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE player ADD forced_sale_week INT DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD club_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD CONSTRAINT fk_98197a656d55acab FOREIGN KEY (club_id) REFERENCES club (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_player_club ON player (club_id)');
        $this->addSql('ALTER TABLE staff ADD club_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE staff ADD CONSTRAINT fk_426ef3926d55acab FOREIGN KEY (club_id) REFERENCES club (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_staff_club ON staff (club_id)');
    }
}

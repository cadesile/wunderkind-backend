<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508093952 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE match_result DROP CONSTRAINT fk_b20538126c52d03a');
        $this->addSql('ALTER TABLE match_result ALTER opponent_club_id DROP NOT NULL');
        $this->addSql('ALTER TABLE match_result ADD CONSTRAINT FK_B20538126C52D03A FOREIGN KEY (opponent_club_id) REFERENCES npc_club (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE match_result DROP CONSTRAINT FK_B20538126C52D03A');
        $this->addSql('ALTER TABLE match_result ALTER opponent_club_id SET NOT NULL');
        $this->addSql('ALTER TABLE match_result ADD CONSTRAINT fk_b20538126c52d03a FOREIGN KEY (opponent_club_id) REFERENCES npc_club (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}

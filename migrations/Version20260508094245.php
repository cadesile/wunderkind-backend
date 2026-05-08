<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508094245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE match_result DROP CONSTRAINT fk_b20538126c52d03a');
        $this->addSql('DROP INDEX idx_b20538126c52d03a');
        $this->addSql('ALTER TABLE match_result DROP opponent_club_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE match_result ADD opponent_club_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE match_result ADD CONSTRAINT fk_b20538126c52d03a FOREIGN KEY (opponent_club_id) REFERENCES npc_club (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_b20538126c52d03a ON match_result (opponent_club_id)');
    }
}

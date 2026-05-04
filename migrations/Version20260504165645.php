<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260504165645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config ALTER reputation_delta_base DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER reputation_delta_facility_multiplier DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER retirement_min_age SET DEFAULT 16');
        $this->addSql('ALTER TABLE game_config ALTER retirement_max_age SET DEFAULT 21');
        $this->addSql('ALTER TABLE game_config ALTER retirement_chance SET DEFAULT 0.5');
        $this->addSql('DROP INDEX uniq_match_result_fixture_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B2053812E524616D ON match_result (fixture_id)');
        $this->addSql('ALTER TABLE transfer DROP CONSTRAINT fk_4034a3c06d55acab');
        $this->addSql('DROP INDEX idx_transfer_npc_club_occurred');
        $this->addSql('ALTER TABLE transfer ADD CONSTRAINT FK_4034A3C061190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config ALTER reputation_delta_base SET DEFAULT \'0.15\'');
        $this->addSql('ALTER TABLE game_config ALTER reputation_delta_facility_multiplier SET DEFAULT \'0.15\'');
        $this->addSql('ALTER TABLE game_config ALTER retirement_min_age SET DEFAULT 30');
        $this->addSql('ALTER TABLE game_config ALTER retirement_max_age SET DEFAULT 38');
        $this->addSql('ALTER TABLE game_config ALTER retirement_chance SET DEFAULT \'0.35\'');
        $this->addSql('DROP INDEX UNIQ_B2053812E524616D');
        $this->addSql('CREATE UNIQUE INDEX uniq_match_result_fixture_id ON match_result (fixture_id) WHERE (fixture_id IS NOT NULL)');
        $this->addSql('ALTER TABLE transfer DROP CONSTRAINT FK_4034A3C061190A32');
        $this->addSql('ALTER TABLE transfer ADD CONSTRAINT fk_4034a3c06d55acab FOREIGN KEY (club_id) REFERENCES club (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_transfer_npc_club_occurred ON transfer (npc_club_id, occurred_at)');
    }
}

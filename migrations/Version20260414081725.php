<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260414081725 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("CREATE TABLE npc_club (id UUID NOT NULL, name VARCHAR(100) NOT NULL, country VARCHAR(2) NOT NULL, tier SMALLINT NOT NULL, reputation SMALLINT NOT NULL, primary_color VARCHAR(7) NOT NULL, secondary_color VARCHAR(7) NOT NULL, stadium_name VARCHAR(100) DEFAULT NULL, balance INT NOT NULL, facilities JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))");
        $this->addSql("COMMENT ON COLUMN npc_club.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN npc_club.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('ALTER TABLE pool_config ADD senior_player_age_min INT NOT NULL DEFAULT 17');
        $this->addSql('ALTER TABLE pool_config ADD senior_player_age_max INT NOT NULL DEFAULT 35');
        $this->addSql('ALTER TABLE pool_config ADD senior_player_ability_min INT NOT NULL DEFAULT 20');
        $this->addSql('ALTER TABLE pool_config ADD senior_player_ability_max INT NOT NULL DEFAULT 90');
        $this->addSql('ALTER TABLE pool_config ADD senior_player_pool_target INT NOT NULL DEFAULT 200');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE npc_club');
        $this->addSql('ALTER TABLE pool_config DROP senior_player_age_min');
        $this->addSql('ALTER TABLE pool_config DROP senior_player_age_max');
        $this->addSql('ALTER TABLE pool_config DROP senior_player_ability_min');
        $this->addSql('ALTER TABLE pool_config DROP senior_player_ability_max');
        $this->addSql('ALTER TABLE pool_config DROP senior_player_pool_target');
    }
}

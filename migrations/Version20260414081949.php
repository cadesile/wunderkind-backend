<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260414081949 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('COMMENT ON COLUMN npc_club.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN npc_club.created_at IS \'\'');
        $this->addSql('ALTER TABLE pool_config ALTER senior_player_age_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER senior_player_age_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER senior_player_ability_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER senior_player_ability_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER senior_player_pool_target DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('COMMENT ON COLUMN npc_club.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN npc_club.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE pool_config ALTER senior_player_age_min SET DEFAULT 17');
        $this->addSql('ALTER TABLE pool_config ALTER senior_player_age_max SET DEFAULT 35');
        $this->addSql('ALTER TABLE pool_config ALTER senior_player_ability_min SET DEFAULT 20');
        $this->addSql('ALTER TABLE pool_config ALTER senior_player_ability_max SET DEFAULT 90');
        $this->addSql('ALTER TABLE pool_config ALTER senior_player_pool_target SET DEFAULT 200');
    }
}

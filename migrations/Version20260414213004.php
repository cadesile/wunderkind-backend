<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260414213004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE league_sponsor (rolled_value BIGINT DEFAULT 0 NOT NULL, league_id UUID NOT NULL, sponsor_id UUID NOT NULL, PRIMARY KEY (league_id, sponsor_id))');
        $this->addSql('CREATE INDEX IDX_3825D0A558AFC4DE ON league_sponsor (league_id)');
        $this->addSql('CREATE INDEX IDX_3825D0A512F7FB51 ON league_sponsor (sponsor_id)');
        $this->addSql('ALTER TABLE league_sponsor ADD CONSTRAINT FK_3825D0A558AFC4DE FOREIGN KEY (league_id) REFERENCES league (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE league_sponsor ADD CONSTRAINT FK_3825D0A512F7FB51 FOREIGN KEY (sponsor_id) REFERENCES sponsor (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE game_config ADD small_sponsor_min BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD small_sponsor_max BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD medium_sponsor_min BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD medium_sponsor_max BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD large_sponsor_min BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD large_sponsor_max BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD league_position_decrease_percent SMALLINT DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE league ADD promotion_spots SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE league ADD tv_deal BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE league ADD league_reputation_tier VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE league ADD prize_money BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE league ADD league_position_pot BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE league_sponsor DROP CONSTRAINT FK_3825D0A558AFC4DE');
        $this->addSql('ALTER TABLE league_sponsor DROP CONSTRAINT FK_3825D0A512F7FB51');
        $this->addSql('DROP TABLE league_sponsor');
        $this->addSql('ALTER TABLE game_config DROP small_sponsor_min');
        $this->addSql('ALTER TABLE game_config DROP small_sponsor_max');
        $this->addSql('ALTER TABLE game_config DROP medium_sponsor_min');
        $this->addSql('ALTER TABLE game_config DROP medium_sponsor_max');
        $this->addSql('ALTER TABLE game_config DROP large_sponsor_min');
        $this->addSql('ALTER TABLE game_config DROP large_sponsor_max');
        $this->addSql('ALTER TABLE game_config DROP league_position_decrease_percent');
        $this->addSql('ALTER TABLE league DROP promotion_spots');
        $this->addSql('ALTER TABLE league DROP tv_deal');
        $this->addSql('ALTER TABLE league DROP league_reputation_tier');
        $this->addSql('ALTER TABLE league DROP prize_money');
        $this->addSql('ALTER TABLE league DROP league_position_pot');
    }
}

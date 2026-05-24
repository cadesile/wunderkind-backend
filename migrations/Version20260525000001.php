<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cards & discipline config to game_config; add yellow_cards/red_cards aggregates to match_result';
    }

    public function up(Schema $schema): void
    {
        // GameConfig — card probability and suspension config
        $this->addSql('ALTER TABLE game_config ADD yellow_card_base_chance DOUBLE PRECISION DEFAULT 0.08 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD red_card_base_chance DOUBLE PRECISION DEFAULT 0.01 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD yellow_card_accumulation_threshold INTEGER DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD red_card_suspension_matches INTEGER DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD temperament_card_scale DOUBLE PRECISION DEFAULT 1.0 NOT NULL');

        // MatchResult — per-match card aggregate counts for analytics
        $this->addSql('ALTER TABLE match_result ADD yellow_cards INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE match_result ADD red_cards INTEGER DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP yellow_card_base_chance');
        $this->addSql('ALTER TABLE game_config DROP red_card_base_chance');
        $this->addSql('ALTER TABLE game_config DROP yellow_card_accumulation_threshold');
        $this->addSql('ALTER TABLE game_config DROP red_card_suspension_matches');
        $this->addSql('ALTER TABLE game_config DROP temperament_card_scale');

        $this->addSql('ALTER TABLE match_result DROP yellow_cards');
        $this->addSql('ALTER TABLE match_result DROP red_cards');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260604231350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_email_verification_user');
        $this->addSql('ALTER TABLE email_verification ADD purpose VARCHAR(20) DEFAULT \'registration\' NOT NULL');
        $this->addSql('COMMENT ON COLUMN email_verification.expires_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN email_verification.verified_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN email_verification.created_at IS \'\'');
        $this->addSql('ALTER TABLE game_config ALTER attribute_hard_cap DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER physical_degradation_age_threshold DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER physical_degradation_rate_mild DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER physical_degradation_rate_moderate DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER physical_degradation_rate_severe DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER physical_degradation_personality_scale DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER form_development_match_count DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER form_development_threshold DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER form_development_multiplier_mid DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER form_development_multiplier_low DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER form_development_threshold_low DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER bankruptcy_deduction_tiers DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER bankruptcy_deductions_enabled DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER squad_role_appearance_expectations DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER squad_role_morale_decay_per_week DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER squad_role_morale_boost_per_week DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER squad_role_auto_assign_thresholds DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER npc_club_balance_ranges DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER npc_facility_level_ranges TYPE JSON');
        $this->addSql('ALTER TABLE game_config ALTER npc_facility_level_ranges DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER league_win_points TYPE JSON');
        $this->addSql('ALTER TABLE game_config ALTER league_win_points DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER pyramid_news_frequency_weeks DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER pyramid_news_config TYPE JSON');
        $this->addSql('ALTER TABLE game_config ALTER pyramid_news_config DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN season_ratings_snapshot.created_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN "user".verified_at IS \'\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email_verification DROP purpose');
        $this->addSql('COMMENT ON COLUMN email_verification.expires_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN email_verification.verified_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('COMMENT ON COLUMN email_verification.created_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('CREATE INDEX idx_email_verification_user ON email_verification (user_id, verified_at, expires_at)');
        $this->addSql('ALTER TABLE game_config ALTER attribute_hard_cap SET DEFAULT 98');
        $this->addSql('ALTER TABLE game_config ALTER physical_degradation_age_threshold SET DEFAULT 30');
        $this->addSql('ALTER TABLE game_config ALTER physical_degradation_rate_mild SET DEFAULT \'0.1\'');
        $this->addSql('ALTER TABLE game_config ALTER physical_degradation_rate_moderate SET DEFAULT \'0.2\'');
        $this->addSql('ALTER TABLE game_config ALTER physical_degradation_rate_severe SET DEFAULT \'0.4\'');
        $this->addSql('ALTER TABLE game_config ALTER physical_degradation_personality_scale SET DEFAULT \'0.2\'');
        $this->addSql('ALTER TABLE game_config ALTER form_development_match_count SET DEFAULT 5');
        $this->addSql('ALTER TABLE game_config ALTER form_development_threshold SET DEFAULT \'7.0\'');
        $this->addSql('ALTER TABLE game_config ALTER form_development_multiplier_mid SET DEFAULT \'0.6\'');
        $this->addSql('ALTER TABLE game_config ALTER form_development_multiplier_low SET DEFAULT \'0.25\'');
        $this->addSql('ALTER TABLE game_config ALTER form_development_threshold_low SET DEFAULT \'5.0\'');
        $this->addSql('ALTER TABLE game_config ALTER bankruptcy_deduction_tiers SET DEFAULT \'[{"deficitMin":1,"deficitMax":200000,"points":3},{"deficitMin":200001,"deficitMax":500000,"points":6},{"deficitMin":500001,"deficitMax":null,"points":10}]\'');
        $this->addSql('ALTER TABLE game_config ALTER bankruptcy_deductions_enabled SET DEFAULT true');
        $this->addSql('ALTER TABLE game_config ALTER npc_club_balance_ranges SET DEFAULT \'[{"min":4000000000,"max":6000000000},{"min":2000000000,"max":3000000000},{"min":1000000000,"max":1500000000},{"min":500000000,"max":750000000},{"min":250000000,"max":375000000},{"min":125000000,"max":187500000},{"min":62500000,"max":93750000},{"min":31250000,"max":46875000}]\'');
        $this->addSql('ALTER TABLE game_config ALTER npc_facility_level_ranges TYPE JSONB');
        $this->addSql('ALTER TABLE game_config ALTER npc_facility_level_ranges SET DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE game_config ALTER squad_role_appearance_expectations SET DEFAULT \'{"star_player":0.9,"important":0.75,"first_team":0.6,"squad_player":0.4,"backup":0.2,"prospect":0.0}\'');
        $this->addSql('ALTER TABLE game_config ALTER squad_role_morale_decay_per_week SET DEFAULT \'{"star_player":-3,"important":-2,"first_team":-2,"squad_player":-1,"backup":0,"prospect":0}\'');
        $this->addSql('ALTER TABLE game_config ALTER squad_role_morale_boost_per_week SET DEFAULT \'{"star_player":0,"important":1,"first_team":1,"squad_player":2,"backup":2,"prospect":3}\'');
        $this->addSql('ALTER TABLE game_config ALTER squad_role_auto_assign_thresholds SET DEFAULT \'{"star_player":80,"important":65,"first_team":50,"squad_player":35,"backup":20,"prospect":0}\'');
        $this->addSql('ALTER TABLE game_config ALTER league_win_points TYPE JSONB');
        $this->addSql('ALTER TABLE game_config ALTER league_win_points SET DEFAULT \'{"1": 10000000, "2": 1000000, "3": 100000, "4": 10000, "5": 1000, "6": 100, "7": 10, "8": 1}\'');
        $this->addSql('ALTER TABLE game_config ALTER pyramid_news_frequency_weeks SET DEFAULT 4');
        $this->addSql('ALTER TABLE game_config ALTER pyramid_news_config TYPE JSONB');
        $this->addSql('ALTER TABLE game_config ALTER pyramid_news_config SET DEFAULT \'{"financial_turmoil": {"usedCacheClearAfterEditions": 5}, "outperforming_critics": {"usedCacheClearAfterEditions": 3}}\'');
        $this->addSql('COMMENT ON COLUMN season_ratings_snapshot.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "user".verified_at IS \'(DC2Type:datetimetz_immutable)\'');
    }
}

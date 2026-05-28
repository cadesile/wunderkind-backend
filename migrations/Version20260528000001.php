<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add physical degradation and form-influence fields to game_config.
 */
final class Version20260528000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add attribute hard cap, physical degradation, and form development fields to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD attribute_hard_cap INTEGER DEFAULT 98 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD physical_degradation_age_threshold INTEGER DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD physical_degradation_rate_mild DOUBLE PRECISION DEFAULT 0.1 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD physical_degradation_rate_moderate DOUBLE PRECISION DEFAULT 0.2 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD physical_degradation_rate_severe DOUBLE PRECISION DEFAULT 0.4 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD physical_degradation_personality_scale DOUBLE PRECISION DEFAULT 0.2 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD form_development_match_count INTEGER DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD form_development_threshold DOUBLE PRECISION DEFAULT 7.0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD form_development_multiplier_mid DOUBLE PRECISION DEFAULT 0.6 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD form_development_multiplier_low DOUBLE PRECISION DEFAULT 0.25 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD form_development_threshold_low DOUBLE PRECISION DEFAULT 5.0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN attribute_hard_cap');
        $this->addSql('ALTER TABLE game_config DROP COLUMN physical_degradation_age_threshold');
        $this->addSql('ALTER TABLE game_config DROP COLUMN physical_degradation_rate_mild');
        $this->addSql('ALTER TABLE game_config DROP COLUMN physical_degradation_rate_moderate');
        $this->addSql('ALTER TABLE game_config DROP COLUMN physical_degradation_rate_severe');
        $this->addSql('ALTER TABLE game_config DROP COLUMN physical_degradation_personality_scale');
        $this->addSql('ALTER TABLE game_config DROP COLUMN form_development_match_count');
        $this->addSql('ALTER TABLE game_config DROP COLUMN form_development_threshold');
        $this->addSql('ALTER TABLE game_config DROP COLUMN form_development_multiplier_mid');
        $this->addSql('ALTER TABLE game_config DROP COLUMN form_development_multiplier_low');
        $this->addSql('ALTER TABLE game_config DROP COLUMN form_development_threshold_low');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Community-stats expansion: loosens SocialPostTemplate's unique key, swaps
 * GameConfig's single-category rotation cursor for a per-period map, and
 * indexes MatchResult.created_at for the new time-windowed queries.
 *
 * The (category, platform) -> (category, platform, period) constraint change is
 * strictly LOOSER (2 columns -> 3): every existing row, already unique on 2
 * columns, trivially satisfies the 3-column version. No dedup needed.
 *
 * last_posted_stat_category is dropped rather than migrated into the new JSON
 * map: app:post-community-stat has no cron trigger anywhere prior to this
 * change, so the column is realistically null in every environment, and there
 * is no principled mapping from a period-unaware cursor onto one of the new
 * period buckets anyway — losing it is a harmless one-time rotation reset.
 */
final class Version20260914090400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community stats expansion: social_post_template period-aware uniqueness, GameConfig per-period rotation, MatchResult.created_at index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_social_post_template_category_platform');
        $this->addSql('CREATE UNIQUE INDEX uq_social_post_template_category_platform_period ON social_post_template (category, platform, period)');

        $this->addSql('ALTER TABLE game_config ADD stat_post_rotation JSON NOT NULL DEFAULT \'{}\'');
        $this->addSql('ALTER TABLE game_config DROP last_posted_stat_category');

        $this->addSql('CREATE INDEX idx_match_result_club_created ON match_result (club_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_match_result_club_created');

        $this->addSql('ALTER TABLE game_config ADD last_posted_stat_category VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE game_config DROP stat_post_rotation');

        $this->addSql('DROP INDEX uq_social_post_template_category_platform_period');
        $this->addSql('CREATE UNIQUE INDEX uq_social_post_template_category_platform ON social_post_template (category, platform)');
    }
}

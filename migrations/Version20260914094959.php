<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin-configurable auto-post schedule for app:post-community-stat-tick.
 *
 * The 4 period tiers previously had a fixed, hardcoded cron schedule baked into the
 * Dockerfile (6-hourly / daily / weekly / monthly). This migration seeds
 * stat_post_schedule with exactly that same cadence for the existing GameConfig row,
 * so the switch to the new tick-based mechanism (Dockerfile, this same deploy) is a
 * pure refactor with no change in behavior until an admin edits the schedule.
 * "month" (interval-hours 720) is an approximation of the old "1st of the month"
 * calendar schedule — an admin can adjust it after this deploy if the drift matters.
 */
final class Version20260914094959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GameConfig.stat_post_schedule / stat_post_last_run_at for admin-configurable auto-post cadence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD stat_post_schedule JSON NOT NULL DEFAULT '{}'");
        $this->addSql("ALTER TABLE game_config ADD stat_post_last_run_at JSON NOT NULL DEFAULT '{}'");

        $this->addSql(<<<'SQL'
            UPDATE game_config SET stat_post_schedule = '{
                "last_6_hours": {"enabled": true, "intervalHours": 6},
                "last_24_hours": {"enabled": true, "intervalHours": 24},
                "week": {"enabled": true, "intervalHours": 168},
                "month": {"enabled": true, "intervalHours": 720}
            }'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP stat_post_last_run_at');
        $this->addSql('ALTER TABLE game_config DROP stat_post_schedule');
    }
}

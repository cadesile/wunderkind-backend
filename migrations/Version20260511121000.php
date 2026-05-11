<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260511121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix league_ability_ranges: coerce any string min/max values to JSON integers';
    }

    public function up(Schema $schema): void
    {
        // Some entries were accidentally stored as JSON strings (e.g. "200") instead
        // of JSON numbers (200). Re-build the column by casting min/max through ::int.
        $this->addSql(<<<'SQL'
            UPDATE starter_config
            SET league_ability_ranges = (
                SELECT json_object_agg(country, tiers_fixed)
                FROM (
                    SELECT country,
                           json_object_agg(tier, jsonb_build_object(
                               'min', (bounds->>'min')::int,
                               'max', (bounds->>'max')::int
                           )) AS tiers_fixed
                    FROM (
                        SELECT country, tier, bounds
                        FROM starter_config sc2,
                             jsonb_each(sc2.league_ability_ranges::jsonb) AS c(country, country_val),
                             jsonb_each(country_val) AS t(tier, bounds)
                        WHERE sc2.id = starter_config.id
                    ) AS flat
                    GROUP BY country
                ) AS rebuilt
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Data-only fix — nothing meaningful to reverse.
    }
}

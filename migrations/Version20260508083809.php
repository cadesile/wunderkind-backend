<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill league financial defaults and fix starter_config.league_ability_ranges.
 *
 * Existing ES leagues had null financials (created before tier defaults existed).
 * starter_config had ES with {min:0,max:0} for all tiers.
 * All countries now share standard 8-tier ability ranges and league financials.
 */
final class Version20260508083809 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill league tier financial defaults and fix starter_config ability ranges for all countries';
    }

    public function up(Schema $schema): void
    {
        // Fix league financial defaults for any league with null tv_deal (ES and any future country)
        $this->addSql("
            UPDATE league SET
                promotion_spots    = CASE tier WHEN 1 THEN NULL ELSE 2 END,
                tv_deal            = CASE tier
                    WHEN 1 THEN 1000000000 WHEN 2 THEN 100000000 WHEN 3 THEN 50000000
                    WHEN 4 THEN 30000000   WHEN 5 THEN 10000000  WHEN 6 THEN 3000000
                    WHEN 7 THEN 1000000    ELSE 500000 END,
                prize_money        = CASE tier
                    WHEN 1 THEN 1000000000 WHEN 2 THEN 100000000 WHEN 3 THEN 50000000
                    WHEN 4 THEN 30000000   WHEN 5 THEN 10000000  WHEN 6 THEN 3000000
                    WHEN 7 THEN 1000000    ELSE 500000 END,
                league_position_pot = CASE tier
                    WHEN 1 THEN 1000000000 WHEN 2 THEN 100000000 WHEN 3 THEN 50000000
                    WHEN 4 THEN 30000000   WHEN 5 THEN 10000000  WHEN 6 THEN 3000000
                    WHEN 7 THEN 1000000    ELSE 500000 END
            WHERE tv_deal IS NULL
        ");

        // Fix starter_config: replace league_ability_ranges with full country set using standard ranges
        $ranges = json_encode($this->buildDefaultRanges());
        $this->addSql("UPDATE starter_config SET league_ability_ranges = '$ranges' WHERE id = 1");
    }

    public function down(Schema $schema): void
    {
        // Restore ES ability ranges to the zeroed state (pre-fix)
        $this->addSql("
            UPDATE starter_config
            SET league_ability_ranges = (
                league_ability_ranges::jsonb || '{\"ES\":{\"1\":{\"min\":0,\"max\":0},\"2\":{\"min\":0,\"max\":0},\"3\":{\"min\":0,\"max\":0},\"4\":{\"min\":0,\"max\":0},\"5\":{\"min\":0,\"max\":0},\"6\":{\"min\":0,\"max\":0},\"7\":{\"min\":0,\"max\":0},\"8\":{\"min\":0,\"max\":0}}}'::jsonb
            )::json
            WHERE id = 1
        ");
    }

    /** Returns standard 8-tier ranges for all supported country codes. */
    private function buildDefaultRanges(): array
    {
        $tiers = [
            '1' => ['min' => 75, 'max' => 95],
            '2' => ['min' => 65, 'max' => 85],
            '3' => ['min' => 55, 'max' => 75],
            '4' => ['min' => 45, 'max' => 65],
            '5' => ['min' => 35, 'max' => 55],
            '6' => ['min' => 25, 'max' => 45],
            '7' => ['min' => 15, 'max' => 35],
            '8' => ['min' => 10, 'max' => 25],
        ];

        $countries = ['EN','ES','IT','DE','FR','PT','NL','BR','AR','NG','GH','CI','SN','JP','KR','SE','DK','IE','CN'];
        $result    = [];
        foreach ($countries as $code) {
            $result[$code] = $tiers;
        }
        return $result;
    }
}

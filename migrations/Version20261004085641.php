<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds GameConfig::$leagueTierDefaults — the DB-backed per-tier League defaults (promotion
 * spots, TV deal, prize money, position pot, sponsor count, trophy design/colour) that
 * replace LeagueService's old hardcoded LEAGUE_TIER_DEFAULTS const, and that the new
 * "League Tier Defaults" admin bulk-edit screen (admin_leagues_overview) edits.
 *
 * Note: `doctrine:migrations:diff` also surfaced a large amount of pre-existing schema drift
 * unrelated to this change (DROP DEFAULT clauses, index renames, and — critically — DROP
 * INDEX on raw-SQL partial unique indexes that aren't representable in ORM metadata, see
 * CLAUDE.md's Testing section). None of that is included here; this migration is scoped to
 * exactly the one column this change needs.
 */
final class Version20261004085641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game_config.league_tier_defaults (per-tier League financial/trophy defaults, DB-backed).';
    }

    public function up(Schema $schema): void
    {
        $default = json_encode([
            '1' => ['promotionSpots' => null, 'tvDeal' => 1_000_000_000, 'prizeMoney' => 1_000_000_000, 'leaguePositionPot' => 1_000_000_000, 'sponsorCount' => 0, 'trophyImage' => null, 'trophyColour' => null],
            '2' => ['promotionSpots' => 2,    'tvDeal' =>   100_000_000, 'prizeMoney' =>   100_000_000, 'leaguePositionPot' =>   100_000_000, 'sponsorCount' => 0, 'trophyImage' => null, 'trophyColour' => null],
            '3' => ['promotionSpots' => 2,    'tvDeal' =>    50_000_000, 'prizeMoney' =>    50_000_000, 'leaguePositionPot' =>    50_000_000, 'sponsorCount' => 0, 'trophyImage' => null, 'trophyColour' => null],
            '4' => ['promotionSpots' => 2,    'tvDeal' =>    30_000_000, 'prizeMoney' =>    30_000_000, 'leaguePositionPot' =>    30_000_000, 'sponsorCount' => 0, 'trophyImage' => null, 'trophyColour' => null],
            '5' => ['promotionSpots' => 2,    'tvDeal' =>    10_000_000, 'prizeMoney' =>    10_000_000, 'leaguePositionPot' =>    10_000_000, 'sponsorCount' => 0, 'trophyImage' => null, 'trophyColour' => null],
            '6' => ['promotionSpots' => 2,    'tvDeal' =>     3_000_000, 'prizeMoney' =>     3_000_000, 'leaguePositionPot' =>     3_000_000, 'sponsorCount' => 0, 'trophyImage' => null, 'trophyColour' => null],
            '7' => ['promotionSpots' => 2,    'tvDeal' =>     1_000_000, 'prizeMoney' =>     1_000_000, 'leaguePositionPot' =>     1_000_000, 'sponsorCount' => 0, 'trophyImage' => null, 'trophyColour' => null],
            '8' => ['promotionSpots' => 2,    'tvDeal' =>       500_000, 'prizeMoney' =>       500_000, 'leaguePositionPot' =>       500_000, 'sponsorCount' => 0, 'trophyImage' => null, 'trophyColour' => null],
        ]);

        $this->addSql("ALTER TABLE game_config ADD league_tier_defaults JSON NOT NULL DEFAULT '{$default}'");
        $this->addSql('ALTER TABLE game_config ALTER league_tier_defaults DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP league_tier_defaults');
    }
}

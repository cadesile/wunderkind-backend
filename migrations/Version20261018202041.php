<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replace the trophy asset slug + colour enum with the 16-bit trophy sprite's config.
 *
 * `league` and `competition_template` each lose `trophy_image` (a `trophy-1`..`trophy-15`
 * filename pointing at one of 15 static black SVGs in public/images/trophies/) and
 * `trophy_colour` (silver/gold/gold_silver, applied as a CSS filter chain), and gain a
 * single nullable `trophy` JSON column holding the sprite's own TrophyConfig —
 * {style, finish, ribbons, ribbonPrimary, ribbonSecondary} — the same
 * store-and-serve-verbatim treatment player.appearance and npc_club.identity get.
 *
 * BACKFILL. Rows carrying a design are converted rather than reset:
 *   - finish: gold stays gold, silver stays silver, gold_silver becomes the sprite's
 *     `twotone`. No admin loses a colour option in the move — only the name changed.
 *   - style: the slugs are spread across the 10 styles in order. There is no artwork
 *     correspondence to preserve (hand-drawn silhouettes vs. procedurally drawn shapes),
 *     and spreading keeps the variety an admin had already configured instead of
 *     collapsing every league onto `classic`. Only trophy-1..trophy-8 were ever in use
 *     (one per league tier), so each tier lands on a distinct style and tier 1 — the top
 *     division — keeps the flagship `classic` cup. Mirrors
 *     TrophyConfigFactory::fromLegacy(), which the world import still uses to read export
 *     files written before this change.
 *   - ribbons default to on in the sprite's own red/white, since the old design had no
 *     ribbon concept at all and off would make every migrated trophy look plainer than
 *     the one it replaced.
 *   - a NULL or unparseable slug stays NULL: "no design set" meant "no trophy", a real
 *     state the honours board and the tier-defaults table both rely on.
 *
 * game_config.league_tier_defaults holds the same pair inside its stored JSON and is NOT
 * touched here — GameConfig::normaliseTierRow() folds legacy rows on read and write, which
 * also covers config export files taken before this change (admins are told those are safe
 * to commit and re-import).
 *
 * The 15 SVG assets are deliberately left in public/images/trophies/ for now: a deployed
 * app build still fetches them until the frontend ships its own TrophySprite. Nothing in
 * this repo references them after this migration.
 */
final class Version20261018202041 extends AbstractMigration
{
    /** Index = (slug number - 1) % 10, matching TrophyConfigFactory::legacyStyleOrder(). */
    private const STYLES = "ARRAY['classic','goblet','loving','urn','chalice','tall','winged','league','ball','bowl']";

    public function getDescription(): string
    {
        return 'Replace league/competition_template trophy_image+trophy_colour with a trophy JSON sprite config.';
    }

    public function up(Schema $schema): void
    {
        foreach (['league', 'competition_template'] as $table) {
            $this->addSql("ALTER TABLE {$table} ADD trophy JSON DEFAULT NULL");

            $this->addSql(sprintf(
                <<<'SQL'
                UPDATE %1$s SET trophy = json_build_object(
                    'style', (%2$s)[((substring(trophy_image FROM 8))::int - 1) %% 10 + 1],
                    'finish', CASE trophy_colour
                        WHEN 'silver' THEN 'silver'
                        WHEN 'gold_silver' THEN 'twotone'
                        ELSE 'gold'
                    END,
                    'ribbons', true,
                    'ribbonPrimary', '#c8202f',
                    'ribbonSecondary', '#f4f3ee'
                )
                WHERE trophy_image ~ '^trophy-[0-9]+$'
                SQL,
                $table,
                self::STYLES,
            ));

            $this->addSql("ALTER TABLE {$table} DROP trophy_image");
            $this->addSql("ALTER TABLE {$table} DROP trophy_colour");
        }
    }

    public function down(Schema $schema): void
    {
        // Reverses the backfill as faithfully as the shapes allow: finish maps back
        // exactly, but a style only names the FIRST slug that produced it (trophy-11's
        // `classic` comes back as trophy-1), since 10 styles cannot encode 15 slugs.
        // Ribbon colours have no legacy counterpart and are dropped.
        foreach (['league', 'competition_template'] as $table) {
            $this->addSql("ALTER TABLE {$table} ADD trophy_image VARCHAR(20) DEFAULT NULL");
            $this->addSql("ALTER TABLE {$table} ADD trophy_colour VARCHAR(255) DEFAULT NULL");

            $this->addSql(sprintf(
                <<<'SQL'
                UPDATE %1$s SET
                    trophy_image = 'trophy-' || (array_position(%2$s, trophy->>'style'))::text,
                    trophy_colour = CASE trophy->>'finish'
                        WHEN 'silver' THEN 'silver'
                        WHEN 'twotone' THEN 'gold_silver'
                        ELSE 'gold'
                    END
                WHERE trophy IS NOT NULL
                  AND array_position(%2$s, trophy->>'style') IS NOT NULL
                SQL,
                $table,
                self::STYLES,
            ));

            $this->addSql("ALTER TABLE {$table} DROP trophy");
        }
    }
}

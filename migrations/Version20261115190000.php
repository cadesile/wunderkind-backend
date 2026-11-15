<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sponsor and ticket prices as a tier-1 base plus a ratio table. Ships inert.
 *
 * ── WHY A RATIO TABLE ON TOP OF `Version20261114100000` ───────────────────────────────────
 *
 * That migration added the EXPLICIT per-tier tables: eight rows x three company sizes x two
 * halves for sponsorship, eight rows for ticket price. It works, and authoring England through
 * it means deriving and typing 48 sponsor figures by hand — then deriving all 48 again the
 * next time the tier-1 band moves. A ladder nobody can retune in one place is a ladder that
 * stops being retuned.
 *
 * This is the same ladder expressed as a base plus eight ratios: the six flat
 * `*_sponsor_min/max` columns stay the tier-1 base, `economy_sponsor_tier_ratio_bp` says what
 * each division is worth against it, and `economy_ticket_price_tier_1_pence` x
 * `economy_ticket_price_tier_ratio_bp` does the same for the gate price the backend has never
 * held a figure for.
 *
 * The explicit tables from `Version20261114100000` are NOT replaced and NOT dropped. They
 * remain the override — `SponsorBandResolver::bandFor()` reads them first — so a division
 * already authored by hand keeps exactly the figures it was authored with.
 *
 * ── TWO RATIO TABLES, NOT ONE "TIER SHAPE" ────────────────────────────────────────────────
 *
 * A ticket and a shirt sponsor do not fall off at the same rate down a pyramid, and neither
 * follows `economy_tier_factors_bp`, which prices wages. Three separate tables is three things
 * to author; one shared table is a wage retune silently re-pricing every sponsor and every
 * ticket in the world. The owner chose the former, for the reason
 * `SponsorBandResolverTest::testTheTierFactorIsDeliberatelyNOTApplied()` already pins.
 *
 * ── ALL THREE SHIP INERT ──────────────────────────────────────────────────────────────────
 *
 * Both ratio tables default to eight 10000s — parity — so every sponsor band resolves to the
 * flat column it resolved to before, byte for byte. `economy_ticket_price_tier_1_pence`
 * defaults to 0, and 0 propagates through the resolver whatever the ratios say, so the
 * published ticket price stays 0 ("unauthored, use your own figure") until an operator
 * authors a base.
 *
 * Add-with-default then DROP DEFAULT on all three — the pattern this series uses so the
 * mapping owns the default afterwards rather than the database. Additive only: no column is
 * dropped, altered or backfilled, so an older build reads exactly what it read before.
 *
 * Reversible: three drops.
 */
final class Version20261115190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-tier sponsor and ticket-price ratio tables plus a tier-1 ticket base to game_config, all unauthored so behaviour is unchanged.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD economy_sponsor_tier_ratio_bp JSON DEFAULT '[10000,10000,10000,10000,10000,10000,10000,10000]' NOT NULL");
        $this->addSql("ALTER TABLE game_config ADD economy_ticket_price_tier_ratio_bp JSON DEFAULT '[10000,10000,10000,10000,10000,10000,10000,10000]' NOT NULL");
        $this->addSql('ALTER TABLE game_config ADD economy_ticket_price_tier1_pence BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ALTER economy_sponsor_tier_ratio_bp DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER economy_ticket_price_tier_ratio_bp DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER economy_ticket_price_tier1_pence DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP economy_sponsor_tier_ratio_bp');
        $this->addSql('ALTER TABLE game_config DROP economy_ticket_price_tier_ratio_bp');
        $this->addSql('ALTER TABLE game_config DROP economy_ticket_price_tier1_pence');
    }
}

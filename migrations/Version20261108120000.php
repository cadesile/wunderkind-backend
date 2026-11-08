<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seat NPC clubs on real land plots.
 *
 * LocationCapacityService has always subtracted NPC clubs from a town's available plots —
 * `min(npcClubs, NPC_PLOT_CAP)` — but it was a COUNT, with nothing recording WHICH plot. So
 * the catalogue could report two plots taken by clubs while a player claimed plot 1, and the
 * world pack had no plot to name for the club standing there. This column is the link that
 * makes the existing rule true.
 *
 * NULLABLE, AND USUALLY NULL. Only the first NPC_PLOT_CAP clubs in a town are seated; the rest
 * occupy nothing, which is the honest reading of a ten-club town with one plot. A town that has
 * never been priced has no plots at all, so its clubs stay unseated until enrichment values it
 * and app:npc-clubs:seat-plots runs again.
 *
 * ON DELETE SET NULL, not RESTRICT. LandPlotProvisioner::reapSurplus() deletes free plots when
 * a town is re-banded downward, and a seated club must not be able to block that: losing a seat
 * is recoverable by re-running the backfill, where a blocked reap leaves a town selling land its
 * size no longer justifies.
 *
 * THE UNIQUE INDEX IS THE POINT. One club per plot, enforced by the database rather than by the
 * code that assigns them — partial, so the many clubs with no seat do not collide with each
 * other on NULL.
 */
final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give npc_club a land_plot_id, so an NPC club occupies a specific plot rather than a count.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE npc_club ADD land_plot_id UUID DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN npc_club.land_plot_id IS \'(DC2Type:uuid)\'');

        $this->addSql(
            'ALTER TABLE npc_club
                ADD CONSTRAINT fk_npc_club_land_plot
                FOREIGN KEY (land_plot_id) REFERENCES land_plot (id)
                ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE',
        );

        $this->addSql('CREATE INDEX idx_npc_club_land_plot ON npc_club (land_plot_id)');

        // Partial: NULL is the normal state for an unseated club, and a plain unique index
        // would be satisfied by NULLs in Postgres anyway — spelled out so the intent survives
        // and so SchemaTool's `where` predicate matches what pg_get_expr reports.
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_npc_club_land_plot ON npc_club (land_plot_id) WHERE land_plot_id IS NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_npc_club_land_plot');
        $this->addSql('DROP INDEX idx_npc_club_land_plot');
        $this->addSql('ALTER TABLE npc_club DROP CONSTRAINT fk_npc_club_land_plot');
        $this->addSql('ALTER TABLE npc_club DROP land_plot_id');
    }
}

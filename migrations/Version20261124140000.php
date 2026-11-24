<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Clear the leftover `(DC2Type:uuid)` comment on `npc_club.land_plot_id`.
 *
 * `Version20261108120000` added the column and wrote that comment by hand, the way Doctrine
 * used to. DBAL 4 no longer emits type comments, so the mapping does not describe it and
 * `doctrine:schema:update --dump-sql` has been reporting `COMMENT ON COLUMN
 * npc_club.land_plot_id IS ''` ever since — the last line of drift on this schema.
 *
 * Cosmetic in effect and still worth a migration, for the reason
 * .context/stages/03_data/output/migrations.md gives: a diff that is never empty trains
 * everyone to read past it, and the next real difference then hides in the noise. This is the
 * same class of leftover, and the same fix, as `Version20261011134810`'s third item
 * (`deletion_request.id`); it is only separate because this column was added a month later.
 *
 * `down()` restores the comment rather than leaving it cleared, so a rollback puts the schema
 * back exactly as `Version20261108120000` left it.
 */
final class Version20261124140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clear the stale (DC2Type:uuid) comment on npc_club.land_plot_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("COMMENT ON COLUMN npc_club.land_plot_id IS ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("COMMENT ON COLUMN npc_club.land_plot_id IS '(DC2Type:uuid)'");
    }
}

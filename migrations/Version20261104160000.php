<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `npc_club.location_id` — the NPC half of what `club.home_location_id` already does.
 *
 * Player clubs got a home when the land economy shipped; NPC clubs did not. `npc_club` carries
 * `region`, `city_size`, `population_size` and `is_capital` as scalars COPIED at generation
 * time, so an NPC club has been a snapshot of a town rather than a reference to one. That meant
 * nothing could answer "which clubs play at this town?", and it let the copies drift from the
 * rows they came from — Leicester and Wakefield (both 355,000) classify into opposite bands on
 * `location` and `npc_club` respectively.
 *
 * NULLABLE, and expected to stay so. A club legitimately has no town when its country was
 * generated before locations were seeded (LocationCatalogueService::PLACEHOLDER_PLACES produces
 * "Capital FC", "Northern Town" — names that will never match a location row), when it came from
 * a hand-edited world-export file, or when an admin renamed it freely. app:npc-clubs:link-locations
 * backfills what it can and leaves the rest null rather than guessing.
 *
 * ON DELETE SET NULL rather than RESTRICT. Nothing in this codebase deletes a Location —
 * retirement is a status flip, as Version20261103120000 spells out — so this only fires under
 * admin surgery, where losing the link is much better than blocking the operation. Contrast
 * land_plot -> location, which is RESTRICT precisely because a plot carries real money; an NPC
 * club carries none.
 *
 * Doctrine's GENERATED index name, not an explicit one: naming it ourselves shows up as a rename
 * on every subsequent schema diff, which is the churn Version20261104100000 had to correct.
 */
final class Version20261104160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'npc_club.location_id: link NPC clubs to the town they play in, mirroring club.home_location_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE npc_club ADD location_id UUID DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE npc_club ADD CONSTRAINT FK_E1A27F4B64D218E
             FOREIGN KEY (location_id) REFERENCES location (id)
             ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE',
        );
        $this->addSql('CREATE INDEX IDX_E1A27F4B64D218E ON npc_club (location_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_E1A27F4B64D218E');
        $this->addSql('ALTER TABLE npc_club DROP CONSTRAINT IF EXISTS FK_E1A27F4B64D218E');
        $this->addSql('ALTER TABLE npc_club DROP COLUMN IF EXISTS location_id');
    }
}

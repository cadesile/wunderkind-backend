<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes the 'morale_surge' ("Rejuvenated") player event template.
 *
 * app:seed-player-events only inserts/updates by slug — it never prunes a slug dropped from
 * the seed command, so this event would otherwise keep firing in every environment that had
 * already seeded it, even after the entry was removed from SeedPlayerEventsCommand.
 */
final class Version20260913203511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Delete the 'morale_surge' player event template";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM game_event_template WHERE slug = 'morale_surge'");
    }

    public function down(Schema $schema): void
    {
        // Content deletion isn't meaningfully reversible — re-run app:seed-player-events
        // against an older revision of SeedPlayerEventsCommand if this needs restoring.
    }
}

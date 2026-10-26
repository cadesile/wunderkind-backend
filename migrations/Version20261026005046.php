<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives the Club Spotlight a facilities payload the redesigned landing page can draw.
 *
 * The page renders all four facility sites (training ground, medical centre, scouting,
 * stadium), and must not query `club` or `club_facility` at render time — the spotlight is
 * a denormalised snapshot precisely so the landing page touches one row. `stadium_config`
 * already holds the stadium alone and is consumed by landing.js as-is, so the other three
 * sites could not be folded into it without breaking that contract; this is a separate
 * column carrying all four in one shape.
 *
 * Additive and nullable with no backfill. Existing spotlight rows stay null until the next
 * generation pass, and a null reads as "no facilities payload" rather than as an empty one —
 * which matters, because every DEFAULT_*_CONFIG in the sprite drop is a demo config, so a
 * page that mistook null for empty would draw someone else's facilities.
 */
final class Version20261026005046 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add club_spotlight.facilities, the four-site sprite config payload for the landing page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_spotlight ADD facilities JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_spotlight DROP facilities');
    }
}

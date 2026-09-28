<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds club.home_kit_config / away_kit_config / badge_config — the real player
 * club's own kit+badge identity, client-supplied via POST /api/club/kit-identity,
 * surfaced on the leaderboard and landing-page telemetry feed.
 */
final class Version20260928200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add club.home_kit_config / away_kit_config / badge_config for chairman-customized kit+badge identity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD home_kit_config JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE club ADD away_kit_config JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE club ADD badge_config JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP home_kit_config');
        $this->addSql('ALTER TABLE club DROP away_kit_config');
        $this->addSql('ALTER TABLE club DROP badge_config');
    }
}

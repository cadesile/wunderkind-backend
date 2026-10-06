<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the one-time '[]' defaults Version20261006114129 needed to backfill
 * the pre-existing club_spotlight row when adding NOT NULL json columns —
 * App\Entity\ClubSpotlight's mapping never declared a DB-level default, so
 * this just brings the schema back in line with it.
 */
final class Version20261006114842 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the temporary defaults on club_spotlight.recent_fixtures/recent_transfers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_spotlight ALTER recent_fixtures DROP DEFAULT');
        $this->addSql('ALTER TABLE club_spotlight ALTER recent_transfers DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE club_spotlight ALTER recent_fixtures SET DEFAULT '[]'");
        $this->addSql("ALTER TABLE club_spotlight ALTER recent_transfers SET DEFAULT '[]'");
    }
}

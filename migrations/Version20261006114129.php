<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds recent fixtures/transfers/top-performer fields to club_spotlight and
 * repurposes club_total_career_earnings as dividend_draws_pence (owner
 * dividend draws, not the club's total income — see App\Entity\ClubSpotlight).
 *
 * Hand-written rather than the raw doctrine:migrations:diff output, same
 * reasoning as Version20261006103425: this dev environment's schema carries
 * unrelated pre-existing drift against ORM metadata that diff would otherwise
 * fold into this migration.
 */
final class Version20261006114129 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recent fixtures/transfers/top-performer fields to club_spotlight; repurpose club_total_career_earnings as dividend_draws_pence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE club_spotlight ADD recent_fixtures JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE club_spotlight ADD recent_transfers JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE club_spotlight ADD top_performer_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE club_spotlight ADD top_performer_goals INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE club_spotlight ADD top_performer_assists INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE club_spotlight ADD top_performer_appearance_config JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE club_spotlight RENAME COLUMN club_total_career_earnings TO dividend_draws_pence');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_spotlight DROP recent_fixtures');
        $this->addSql('ALTER TABLE club_spotlight DROP recent_transfers');
        $this->addSql('ALTER TABLE club_spotlight DROP top_performer_name');
        $this->addSql('ALTER TABLE club_spotlight DROP top_performer_goals');
        $this->addSql('ALTER TABLE club_spotlight DROP top_performer_assists');
        $this->addSql('ALTER TABLE club_spotlight DROP top_performer_appearance_config');
        $this->addSql('ALTER TABLE club_spotlight RENAME COLUMN dividend_draws_pence TO club_total_career_earnings');
    }
}

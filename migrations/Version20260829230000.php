<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the columns the new club_goals / club_assists / iron_man / transfer_record / transfer_spend leaderboards sort on.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_player_career_stat_appearances ON player_career_stat (appearances)');
        $this->addSql('CREATE INDEX idx_transfer_type_fee ON transfer (type, fee)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_player_career_stat_appearances');
        $this->addSql('DROP INDEX idx_transfer_type_fee');
    }
}

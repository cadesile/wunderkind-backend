<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260417175315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename academy to club and update foreign keys';
    }

    public function up(Schema $schema): void
    {
        // 1. Rename the main table
        $this->addSql('ALTER TABLE academy RENAME TO club');
        $this->addSql('ALTER INDEX uniq_994d0ecba76ed395 RENAME TO UNIQ_B8EE3872A76ED395');
        $this->addSql('ALTER INDEX idx_994d0ecb83897572 RENAME TO IDX_B8EE387283897572');
        
        // 2. Rename columns and constraints in related tables
        $this->addSql('ALTER TABLE guardian RENAME COLUMN loyalty_to_academy TO loyalty_to_club');
        
        $this->addSql('ALTER TABLE inbox_message RENAME COLUMN academy_id TO club_id');
        $this->addSql('ALTER INDEX idx_inbox_academy RENAME TO IDX_inbox_club');
        
        $this->addSql('ALTER TABLE investor RENAME COLUMN academy_id TO club_id');
        $this->addSql('ALTER INDEX idx_8bbaed266d55acab RENAME TO IDX_8BBAED2661190A32');
        
        $this->addSql('ALTER TABLE leaderboard_entry RENAME COLUMN academy_id TO club_id');
        $this->addSql('ALTER INDEX idx_3b08bddb6d55acab RENAME TO IDX_3B08BDDB61190A32');
        $this->addSql('ALTER INDEX uq_leaderboard_academy_category_period RENAME TO uq_leaderboard_club_category_period');
        
        $this->addSql('ALTER TABLE match_result RENAME COLUMN academy_id TO club_id');
        $this->addSql('ALTER INDEX idx_b20538126d55acab RENAME TO IDX_B205381261190A32');
        
        $this->addSql('ALTER TABLE player RENAME COLUMN academy_id TO club_id');
        $this->addSql('ALTER INDEX idx_player_academy RENAME TO idx_player_club');
        
        $this->addSql('ALTER TABLE season_record RENAME COLUMN academy_id TO club_id');
        $this->addSql('ALTER INDEX idx_8d4f61236d55acab RENAME TO IDX_8D4F612361190A32');
        
        $this->addSql('ALTER TABLE season_snapshot RENAME COLUMN academy_id TO club_id');
        $this->addSql('ALTER INDEX idx_a0bb106f6d55acab RENAME TO IDX_A0BB106F61190A32');
        
        $this->addSql('ALTER TABLE sponsor RENAME COLUMN academy_id TO club_id');
        $this->addSql('ALTER INDEX idx_818cc9d46d55acab RENAME TO IDX_818CC9D461190A32');
        
        $this->addSql('ALTER TABLE staff RENAME COLUMN academy_id TO club_id');
        $this->addSql('ALTER INDEX idx_staff_academy RENAME TO idx_staff_club');
        
        $this->addSql('ALTER TABLE starter_config RENAME COLUMN starter_academy_tier TO starter_club_tier');
        
        $this->addSql('ALTER TABLE sync_record RENAME COLUMN academy_id TO club_id');
        $this->addSql('ALTER INDEX idx_33b5714e6d55acab RENAME TO IDX_33B5714E61190A32');
        
        $this->addSql('ALTER TABLE transfer RENAME COLUMN academy_id TO club_id');
        $this->addSql('ALTER INDEX idx_transfer_academy_occurred RENAME TO idx_transfer_club_occurred');
        $this->addSql('ALTER INDEX idx_4034a3c06d55acab RENAME TO IDX_4034A3C061190A32');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club RENAME TO academy');
        $this->addSql('ALTER INDEX UNIQ_B8EE3872A76ED395 RENAME TO uniq_994d0ecba76ed395');
        $this->addSql('ALTER INDEX IDX_B8EE387283897572 RENAME TO idx_994d0ecb83897572');
        
        $this->addSql('ALTER TABLE guardian RENAME COLUMN loyalty_to_club TO loyalty_to_academy');
        
        $this->addSql('ALTER TABLE inbox_message RENAME COLUMN club_id TO academy_id');
        $this->addSql('ALTER INDEX IDX_inbox_club RENAME TO idx_inbox_academy');
        
        $this->addSql('ALTER TABLE investor RENAME COLUMN club_id TO academy_id');
        $this->addSql('ALTER INDEX IDX_8BBAED2661190A32 RENAME TO idx_8bbaed266d55acab');
        
        $this->addSql('ALTER TABLE leaderboard_entry RENAME COLUMN club_id TO academy_id');
        $this->addSql('ALTER INDEX IDX_3B08BDDB61190A32 RENAME TO idx_3b08bddb6d55acab');
        $this->addSql('ALTER INDEX uq_leaderboard_club_category_period RENAME TO uq_leaderboard_academy_category_period');
        
        $this->addSql('ALTER TABLE match_result RENAME COLUMN club_id TO academy_id');
        $this->addSql('ALTER INDEX IDX_B205381261190A32 RENAME TO idx_b20538126d55acab');
        
        $this->addSql('ALTER TABLE player RENAME COLUMN club_id TO academy_id');
        $this->addSql('ALTER INDEX idx_player_club RENAME TO idx_player_academy');
        
        $this->addSql('ALTER TABLE season_record RENAME COLUMN club_id TO academy_id');
        $this->addSql('ALTER INDEX IDX_8D4F612361190A32 RENAME TO idx_8d4f61236d55acab');
        
        $this->addSql('ALTER TABLE season_snapshot RENAME COLUMN club_id TO academy_id');
        $this->addSql('ALTER INDEX IDX_A0BB106F61190A32 RENAME TO idx_a0bb106f6d55acab');
        
        $this->addSql('ALTER TABLE sponsor RENAME COLUMN club_id TO academy_id');
        $this->addSql('ALTER INDEX IDX_818CC9D461190A32 RENAME TO idx_818cc9d46d55acab');
        
        $this->addSql('ALTER TABLE staff RENAME COLUMN club_id TO academy_id');
        $this->addSql('ALTER INDEX idx_staff_club RENAME TO idx_staff_academy');
        
        $this->addSql('ALTER TABLE starter_config RENAME COLUMN starter_club_tier TO starter_academy_tier');
        
        $this->addSql('ALTER TABLE sync_record RENAME COLUMN club_id TO academy_id');
        $this->addSql('ALTER INDEX IDX_33B5714E61190A32 RENAME TO idx_33b5714e6d55acab');
        
        $this->addSql('ALTER TABLE transfer RENAME COLUMN club_id TO academy_id');
        $this->addSql('ALTER INDEX idx_transfer_club_occurred RENAME TO idx_transfer_academy_occurred');
        $this->addSql('ALTER INDEX IDX_4034A3C061190A32 RENAME TO idx_4034a3c06d55acab');
    }
}

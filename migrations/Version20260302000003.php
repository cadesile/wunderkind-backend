<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Market pool: nullable academy on player/staff, academy FK on sponsor/investor, market_pool_size on academy, indexes';
    }

    public function up(Schema $schema): void
    {
        // Make player.academy_id nullable + add index
        $this->addSql('ALTER TABLE player MODIFY academy_id BINARY(16) NULL');
        $this->addSql('CREATE INDEX idx_player_academy ON player (academy_id)');

        // Make staff.academy_id nullable + add index
        $this->addSql('ALTER TABLE staff MODIFY academy_id BINARY(16) NULL');
        $this->addSql('CREATE INDEX idx_staff_academy ON staff (academy_id)');

        // Add academy FK to sponsor
        $this->addSql('ALTER TABLE sponsor ADD academy_id BINARY(16) NULL');
        $this->addSql('ALTER TABLE sponsor ADD CONSTRAINT FK_SPONSOR_ACADEMY FOREIGN KEY (academy_id) REFERENCES academy (id) ON DELETE SET NULL');

        // Add academy FK to investor
        $this->addSql('ALTER TABLE investor ADD academy_id BINARY(16) NULL');
        $this->addSql('ALTER TABLE investor ADD CONSTRAINT FK_INVESTOR_ACADEMY FOREIGN KEY (academy_id) REFERENCES academy (id) ON DELETE SET NULL');

        // Add market_pool_size to academy
        $this->addSql('ALTER TABLE academy ADD market_pool_size INT UNSIGNED NOT NULL DEFAULT 20');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academy DROP COLUMN market_pool_size');

        $this->addSql('ALTER TABLE investor DROP FOREIGN KEY FK_INVESTOR_ACADEMY');
        $this->addSql('ALTER TABLE investor DROP COLUMN academy_id');

        $this->addSql('ALTER TABLE sponsor DROP FOREIGN KEY FK_SPONSOR_ACADEMY');
        $this->addSql('ALTER TABLE sponsor DROP COLUMN academy_id');

        $this->addSql('DROP INDEX idx_staff_academy ON staff');
        $this->addSql('ALTER TABLE staff MODIFY academy_id BINARY(16) NOT NULL');

        $this->addSql('DROP INDEX idx_player_academy ON player');
        $this->addSql('ALTER TABLE player MODIFY academy_id BINARY(16) NOT NULL');
    }
}

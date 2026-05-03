<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add attendance range columns to game_config (% of capacity per reputation tier)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config
            ADD attendance_local_min    SMALLINT DEFAULT 10  NOT NULL,
            ADD attendance_local_max    SMALLINT DEFAULT 30  NOT NULL,
            ADD attendance_regional_min SMALLINT DEFAULT 30  NOT NULL,
            ADD attendance_regional_max SMALLINT DEFAULT 55  NOT NULL,
            ADD attendance_national_min SMALLINT DEFAULT 55  NOT NULL,
            ADD attendance_national_max SMALLINT DEFAULT 80  NOT NULL,
            ADD attendance_elite_min    SMALLINT DEFAULT 80  NOT NULL,
            ADD attendance_elite_max    SMALLINT DEFAULT 100 NOT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config
            DROP COLUMN attendance_local_min,
            DROP COLUMN attendance_local_max,
            DROP COLUMN attendance_regional_min,
            DROP COLUMN attendance_regional_max,
            DROP COLUMN attendance_national_min,
            DROP COLUMN attendance_national_max,
            DROP COLUMN attendance_elite_min,
            DROP COLUMN attendance_elite_max
        ');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add npc_club_balance_ranges JSON column to game_config';
    }

    public function up(Schema $schema): void
    {
        $default = json_encode([
            ['min' => 4_000_000_000, 'max' => 6_000_000_000],
            ['min' => 2_000_000_000, 'max' => 3_000_000_000],
            ['min' => 1_000_000_000, 'max' => 1_500_000_000],
            ['min' =>   500_000_000, 'max' =>   750_000_000],
            ['min' =>   250_000_000, 'max' =>   375_000_000],
            ['min' =>   125_000_000, 'max' =>   187_500_000],
            ['min' =>    62_500_000, 'max' =>    93_750_000],
            ['min' =>    31_250_000, 'max' =>    46_875_000],
        ]);

        $this->addSql(
            "ALTER TABLE game_config ADD COLUMN npc_club_balance_ranges JSON NOT NULL DEFAULT '" . $default . "'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN npc_club_balance_ranges');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707203954 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ALTER tutorial_completed_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE game_config ADD npc_squad_config JSON DEFAULT NULL');
        $default = json_encode([
            ['squadMin' => 35, 'squadMax' => 40, 'managers' => 1, 'coaches' => 11, 'chairmen' => 1, 'foreignPercent' => 56],
            ['squadMin' => 30, 'squadMax' => 35, 'managers' => 1, 'coaches' => 9,  'chairmen' => 1, 'foreignPercent' => 45],
            ['squadMin' => 30, 'squadMax' => 35, 'managers' => 1, 'coaches' => 8,  'chairmen' => 1, 'foreignPercent' => 18],
            ['squadMin' => 30, 'squadMax' => 35, 'managers' => 1, 'coaches' => 7,  'chairmen' => 1, 'foreignPercent' => 11],
            ['squadMin' => 30, 'squadMax' => 35, 'managers' => 1, 'coaches' => 7,  'chairmen' => 1, 'foreignPercent' => 6],
            ['squadMin' => 25, 'squadMax' => 30, 'managers' => 1, 'coaches' => 7,  'chairmen' => 1, 'foreignPercent' => 6],
            ['squadMin' => 25, 'squadMax' => 30, 'managers' => 1, 'coaches' => 5,  'chairmen' => 1, 'foreignPercent' => 3],
            ['squadMin' => 22, 'squadMax' => 28, 'managers' => 1, 'coaches' => 5,  'chairmen' => 1, 'foreignPercent' => 0],
        ]);
        $this->addSql("UPDATE game_config SET npc_squad_config = '" . $default . "' WHERE npc_squad_config IS NULL");
        $this->addSql('ALTER TABLE game_config ALTER npc_squad_config SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE club ALTER tutorial_completed_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE game_config DROP npc_squad_config');
    }
}

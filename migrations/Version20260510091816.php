<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510091816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wage multiplier tiers and contract value rand range to game_config';
    }

    public function up(Schema $schema): void
    {
        $default = json_encode([
            ['maxRep' => 100,  'playerMultiplier' => 0.5, 'staffMultiplier' => 0.6],
            ['maxRep' => 300,  'playerMultiplier' => 1.0, 'staffMultiplier' => 1.0],
            ['maxRep' => 600,  'playerMultiplier' => 2.5, 'staffMultiplier' => 2.0],
            ['maxRep' => null, 'playerMultiplier' => 5.0, 'staffMultiplier' => 4.0],
        ]);
        $this->addSql("ALTER TABLE game_config ADD wage_multiplier_tiers JSON NOT NULL DEFAULT '{$default}'");
        $this->addSql('ALTER TABLE game_config ALTER COLUMN wage_multiplier_tiers DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ADD contract_value_rand_min INT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD contract_value_rand_max INT DEFAULT 40 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP wage_multiplier_tiers');
        $this->addSql('ALTER TABLE game_config DROP contract_value_rand_min');
        $this->addSql('ALTER TABLE game_config DROP contract_value_rand_max');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add bankruptcy/financial-penalty deduction fields to game_config.
 */
final class Version20260529000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bankruptcy_deduction_tiers and bankruptcy_deductions_enabled to game_config';
    }

    public function up(Schema $schema): void
    {
        $defaultTiers = json_encode([
            ['deficitMin' => 1,      'deficitMax' => 200000, 'points' => 3],
            ['deficitMin' => 200001, 'deficitMax' => 500000, 'points' => 6],
            ['deficitMin' => 500001, 'deficitMax' => null,   'points' => 10],
        ]);

        $this->addSql("ALTER TABLE game_config ADD bankruptcy_deduction_tiers JSON DEFAULT '{$defaultTiers}' NOT NULL");
        $this->addSql('ALTER TABLE game_config ADD bankruptcy_deductions_enabled BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN bankruptcy_deduction_tiers');
        $this->addSql('ALTER TABLE game_config DROP COLUMN bankruptcy_deductions_enabled');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260419105604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE starter_config ADD league_ability_ranges JSON DEFAULT \'{"EN": {"1": {"min": 75, "max": 95}, "2": {"min": 65, "max": 85}, "3": {"min": 55, "max": 75}, "4": {"min": 45, "max": 65}, "5": {"min": 35, "max": 55}, "6": {"min": 25, "max": 45}, "7": {"min": 15, "max": 35}, "8": {"min": 10, "max": 25}}}\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE starter_config DROP league_ability_ranges');
    }
}

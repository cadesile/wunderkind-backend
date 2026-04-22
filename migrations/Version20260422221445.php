<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422221445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove assistant_coach staff role — migrate existing rows to coach, drop pool_config column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE staff SET role = 'coach' WHERE role = 'assistant_coach'");
        $this->addSql('ALTER TABLE pool_config DROP assistant_coach_pool_target');
        $this->addSql('ALTER TABLE pool_config ALTER manager_pool_target DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER director_of_football_pool_target DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER facility_manager_pool_target DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER chairman_pool_target DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pool_config ADD assistant_coach_pool_target INT DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE pool_config ALTER manager_pool_target SET DEFAULT 5');
        $this->addSql('ALTER TABLE pool_config ALTER director_of_football_pool_target SET DEFAULT 2');
        $this->addSql('ALTER TABLE pool_config ALTER facility_manager_pool_target SET DEFAULT 3');
        $this->addSql('ALTER TABLE pool_config ALTER chairman_pool_target SET DEFAULT 2');
    }
}

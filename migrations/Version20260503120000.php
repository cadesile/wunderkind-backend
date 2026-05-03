<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add android_download_url and ios_download_url to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD android_download_url VARCHAR(500) DEFAULT NULL, ADD ios_download_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN android_download_url, DROP COLUMN ios_download_url');
    }
}

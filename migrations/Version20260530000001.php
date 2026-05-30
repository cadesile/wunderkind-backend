<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add beta request email and reCAPTCHA keys to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD COLUMN beta_request_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE game_config ADD COLUMN recaptcha_site_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE game_config ADD COLUMN recaptcha_secret_key VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN recaptcha_secret_key');
        $this->addSql('ALTER TABLE game_config DROP COLUMN recaptcha_site_key');
        $this->addSql('ALTER TABLE game_config DROP COLUMN beta_request_email');
    }
}

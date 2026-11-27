<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The client version gate: game_config.min_client_api_version (0 = off).
 * See GameConfig::$minClientApiVersion and ClientVersionGateSubscriber.
 */
final class Version20261126170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game_config.min_client_api_version for the update-required gate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD min_client_api_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP min_client_api_version');
    }
}

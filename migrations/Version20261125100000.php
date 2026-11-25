<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * game_config.client_tuning: admin overrides for the 105 engine tuning keys the app used to
 * hold only in its own code (see App\Service\ClientTuningCatalogue). Starts empty, which
 * resolves every key to the app's own default, so applying this changes nothing in game.
 */
final class Version20261125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game_config.client_tuning (JSON overrides for client engine tuning keys).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD client_tuning JSON DEFAULT '{}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP client_tuning');
    }
}

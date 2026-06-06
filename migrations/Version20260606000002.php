<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add max_sponsors_by_tier and max_investors_by_tier to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD COLUMN max_sponsors_by_tier JSON NOT NULL DEFAULT '{\"local\":3,\"regional\":5,\"national\":7,\"elite\":10}'");
        $this->addSql("ALTER TABLE game_config ADD COLUMN max_investors_by_tier JSON NOT NULL DEFAULT '{\"local\":3,\"regional\":5,\"national\":7,\"elite\":10}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN max_sponsors_by_tier');
        $this->addSql('ALTER TABLE game_config DROP COLUMN max_investors_by_tier');
    }
}

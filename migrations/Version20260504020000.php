<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen match_result.fixture_id from VARCHAR(36) to VARCHAR(255) for composite client IDs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE match_result ALTER COLUMN fixture_id TYPE VARCHAR(255)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE match_result ALTER COLUMN fixture_id TYPE VARCHAR(36)');
    }
}

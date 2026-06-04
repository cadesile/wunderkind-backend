<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow multiple clubs per user — drop unique constraint on club.user_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_b8ee3872a76ed395');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_b8ee3872a76ed395 ON club (user_id)');
    }
}

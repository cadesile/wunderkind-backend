<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written rather than generated. `doctrine:migrations:diff` on this
 * codebase emits ~100 unrelated statements from pre-existing drift between a
 * local dev DB and the ORM mapping, including DROPs of four raw-SQL partial
 * unique indexes that are invisible to Doctrine but load-bearing in production
 * (see CLAUDE.md, and the note on Version20261011114336).
 *
 * DEFAULT 0 matters: existing rows must land on the same value an absent `tv`
 * claim is read as, or every already-issued token would be rejected the moment
 * this deploys.
 */
final class Version20261011131620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.token_version so an admin can invalidate every access token already issued to an account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD token_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP token_version');
    }
}

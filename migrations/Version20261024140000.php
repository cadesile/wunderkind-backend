<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets POST /api/initialize/starter be retried instead of answering 409 forever.
 *
 * The starter squad is drawn from the Player/Staff pool, snapshotted, and the pool rows are
 * then deleted — so the HTTP response was the only copy that ever existed. A response lost in
 * transit (a cancelled request, a backgrounded app, a crash mid-"Assembling your squad…")
 * left the club initialized with a squad it had never received, and no way to ask again.
 *
 * Existing clubs stay null: their squads really are unrecoverable, and those keep the 409.
 */
final class Version20261024140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add club.starter_pack_json so an already-initialized club can be served its starter pack again.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD starter_pack_json JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP starter_pack_json');
    }
}

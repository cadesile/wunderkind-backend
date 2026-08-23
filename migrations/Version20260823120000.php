<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let agents be deleted while pool players still point at them.
 *
 * Agents are a shared pool that admin can clear wholesale (investor pool clear)
 * or row-by-row in EasyAdmin; player.agent_id is a soft association that is
 * re-rolled at world-pack generation anyway, so detaching is the correct
 * behaviour rather than blocking the delete.
 */
final class Version20260823120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'player.agent_id FK becomes ON DELETE SET NULL.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP CONSTRAINT fk_98197a653414710b');
        $this->addSql('ALTER TABLE player ADD CONSTRAINT fk_98197a653414710b FOREIGN KEY (agent_id) REFERENCES agent (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP CONSTRAINT fk_98197a653414710b');
        $this->addSql('ALTER TABLE player ADD CONSTRAINT fk_98197a653414710b FOREIGN KEY (agent_id) REFERENCES agent (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}

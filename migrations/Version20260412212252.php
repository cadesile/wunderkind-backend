<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260412212252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX uq_facility_template_slug RENAME TO UNIQ_C163A60C989D9B62');
        $this->addSql('ALTER TABLE game_config ALTER debug_logging_enabled DROP DEFAULT');
        $this->addSql('ALTER TABLE game_event_template ADD chained_events JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX uniq_c163a60c989d9b62 RENAME TO uq_facility_template_slug');
        $this->addSql('ALTER TABLE game_config ALTER debug_logging_enabled SET DEFAULT false');
        $this->addSql('ALTER TABLE game_event_template DROP chained_events');
    }
}

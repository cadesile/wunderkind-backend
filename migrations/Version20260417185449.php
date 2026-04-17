<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260417185449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tactical_advantage (id UUID NOT NULL, style VARCHAR(255) NOT NULL, opponent_style VARCHAR(255) NOT NULL, multiplier DOUBLE PRECISION NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE club ADD formation VARCHAR(255) DEFAULT \'4-4-2\' NOT NULL');
        $this->addSql('ALTER TABLE npc_club ADD formation VARCHAR(255) DEFAULT \'4-4-2\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE tactical_advantage');
        $this->addSql('ALTER TABLE club DROP formation');
        $this->addSql('ALTER TABLE npc_club DROP formation');
    }
}

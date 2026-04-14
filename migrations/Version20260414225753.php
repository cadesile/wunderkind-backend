<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260414225753 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE league_sponsor_income (rolled_value BIGINT DEFAULT 0 NOT NULL, league_id UUID NOT NULL, sponsor_id UUID NOT NULL, PRIMARY KEY (league_id, sponsor_id))');
        $this->addSql('CREATE INDEX IDX_FD88158F58AFC4DE ON league_sponsor_income (league_id)');
        $this->addSql('CREATE INDEX IDX_FD88158F12F7FB51 ON league_sponsor_income (sponsor_id)');
        $this->addSql('ALTER TABLE league_sponsor_income ADD CONSTRAINT FK_FD88158F58AFC4DE FOREIGN KEY (league_id) REFERENCES league (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE league_sponsor_income ADD CONSTRAINT FK_FD88158F12F7FB51 FOREIGN KEY (sponsor_id) REFERENCES sponsor (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE league_sponsor DROP rolled_value');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE league_sponsor_income DROP CONSTRAINT FK_FD88158F58AFC4DE');
        $this->addSql('ALTER TABLE league_sponsor_income DROP CONSTRAINT FK_FD88158F12F7FB51');
        $this->addSql('DROP TABLE league_sponsor_income');
        $this->addSql('ALTER TABLE league_sponsor ADD rolled_value BIGINT DEFAULT 0 NOT NULL');
    }
}

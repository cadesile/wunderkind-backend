<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260303201455 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE facility DROP FOREIGN KEY `FK_facility_academy`');
        $this->addSql('ALTER TABLE facility CHANGE type type VARCHAR(255) NOT NULL, CHANGE last_upgraded_at last_upgraded_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE facility ADD CONSTRAINT FK_105994B26D55ACAB FOREIGN KEY (academy_id) REFERENCES academy (id)');
        $this->addSql('ALTER TABLE facility RENAME INDEX idx_facility_academy TO IDX_105994B26D55ACAB');
        $this->addSql('ALTER TABLE facility RENAME INDEX uniq_facility_type TO UNIQ_105994B26D55ACAB8CDE5729');
        $this->addSql('ALTER TABLE sponsor CHANGE last_payment_at last_payment_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE facility DROP FOREIGN KEY FK_105994B26D55ACAB');
        $this->addSql('ALTER TABLE facility CHANGE type type VARCHAR(30) NOT NULL, CHANGE last_upgraded_at last_upgraded_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE facility ADD CONSTRAINT `FK_facility_academy` FOREIGN KEY (academy_id) REFERENCES academy (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE facility RENAME INDEX idx_105994b26d55acab TO IDX_facility_academy');
        $this->addSql('ALTER TABLE facility RENAME INDEX uniq_105994b26d55acab8cde5729 TO UNIQ_facility_type');
        $this->addSql('ALTER TABLE sponsor CHANGE last_payment_at last_payment_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}

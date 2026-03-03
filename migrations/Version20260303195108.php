<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260303195108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add balance to academy, morale to player/staff, specialty to staff';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academy ADD balance INT NOT NULL DEFAULT 0, CHANGE financial_year_start financial_year_start SMALLINT UNSIGNED DEFAULT 4 NOT NULL');
        $this->addSql('ALTER TABLE inbox_message CHANGE sender_type sender_type VARCHAR(255) NOT NULL, CHANGE status status VARCHAR(255) DEFAULT \'unread\' NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE responded_at responded_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE investor CHANGE tier tier VARCHAR(255) DEFAULT \'angel\' NOT NULL, CHANGE invested_at invested_at DATETIME DEFAULT NULL, CHANGE last_payout_at last_payout_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD morale INT NOT NULL DEFAULT 50');
        $this->addSql('ALTER TABLE sponsor CHANGE contract_start_date contract_start_date DATETIME DEFAULT NULL, CHANGE contract_end_date contract_end_date DATETIME DEFAULT NULL, CHANGE status status VARCHAR(255) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE staff ADD morale INT NOT NULL DEFAULT 50, ADD specialty VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE academy DROP balance, CHANGE financial_year_start financial_year_start TINYINT DEFAULT 4 NOT NULL');
        $this->addSql('ALTER TABLE inbox_message CHANGE sender_type sender_type VARCHAR(30) NOT NULL, CHANGE status status VARCHAR(30) DEFAULT \'unread\' NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE responded_at responded_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE investor CHANGE tier tier VARCHAR(30) DEFAULT \'angel\' NOT NULL, CHANGE invested_at invested_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE last_payout_at last_payout_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE player DROP morale');
        $this->addSql('ALTER TABLE sponsor CHANGE contract_start_date contract_start_date DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE contract_end_date contract_end_date DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE status status VARCHAR(30) DEFAULT \'active\' NOT NULL');
        $this->addSql('ALTER TABLE staff DROP morale, DROP specialty');
    }
}

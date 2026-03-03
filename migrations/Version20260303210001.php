<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303210001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add facility table and sponsor last_payment_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE facility (
                id BINARY(16) NOT NULL,
                academy_id BINARY(16) NOT NULL,
                type VARCHAR(30) NOT NULL,
                level SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                last_upgraded_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_facility_academy (academy_id),
                UNIQUE INDEX UNIQ_facility_type (academy_id, type),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->addSql('ALTER TABLE facility ADD CONSTRAINT FK_facility_academy FOREIGN KEY (academy_id) REFERENCES academy (id) ON DELETE CASCADE');

        $this->addSql("ALTER TABLE sponsor ADD last_payment_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility DROP FOREIGN KEY FK_facility_academy');
        $this->addSql('DROP TABLE facility');
        $this->addSql('ALTER TABLE sponsor DROP last_payment_at');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024012500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add computation_audit_json column to competition_result for engine-agnostic match derivation auditing.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_result ADD computation_audit_json JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_result DROP computation_audit_json');
    }
}

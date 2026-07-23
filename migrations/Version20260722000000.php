<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add image_path to facility_template — filename of the uploaded facility
 * image, stored under public/uploads/facilities. Nullable.
 */
final class Version20260722000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image_path to facility_template';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility_template ADD image_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility_template DROP image_path');
    }
}

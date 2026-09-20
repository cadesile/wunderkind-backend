<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add trophy_image + trophy_colour to competition_template, mirroring the columns
 * already present on league.
 */
final class Version20260920185614 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add trophy_image + trophy_colour to competition_template';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_template ADD trophy_image VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_template ADD trophy_colour VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_template DROP trophy_image');
        $this->addSql('ALTER TABLE competition_template DROP trophy_colour');
    }
}

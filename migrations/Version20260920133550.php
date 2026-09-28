<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920133550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add auto_fill_spoof_entrants and auto_fill_delay_minutes to competition_template';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_template ADD auto_fill_spoof_entrants BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE competition_template ADD auto_fill_delay_minutes SMALLINT DEFAULT 20 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_template DROP auto_fill_spoof_entrants');
        $this->addSql('ALTER TABLE competition_template DROP auto_fill_delay_minutes');
    }
}

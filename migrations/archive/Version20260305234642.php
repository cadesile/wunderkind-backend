<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260305234642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Player granular attributes (pace/technical/vision/power/stamina/heart/height/weight) + Staff specialisms JSON';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player ADD pace SMALLINT UNSIGNED DEFAULT 0 NOT NULL, ADD technical SMALLINT UNSIGNED DEFAULT 0 NOT NULL, ADD vision SMALLINT UNSIGNED DEFAULT 0 NOT NULL, ADD power SMALLINT UNSIGNED DEFAULT 0 NOT NULL, ADD stamina SMALLINT UNSIGNED DEFAULT 0 NOT NULL, ADD heart SMALLINT UNSIGNED DEFAULT 0 NOT NULL, ADD height SMALLINT UNSIGNED DEFAULT 0 NOT NULL, ADD weight SMALLINT UNSIGNED DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE staff ADD specialisms JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player DROP pace, DROP technical, DROP vision, DROP power, DROP stamina, DROP heart, DROP height, DROP weight');
        $this->addSql('ALTER TABLE staff DROP specialisms');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let an operator author a country's PERSON-name pools without a deploy.
 *
 * Players, staff, scouts, agents and guardians are named from a 2,500-line PHP constant
 * (now NamePoolRegistry). Thirty-two demonyms have a pool; thirty-nine do not, which is
 * every country added in the 2026-10-25 UEFA batch — and a country with no pool cannot be
 * given one without shipping code.
 *
 * TWO NULLABLE JSON COLUMNS, exactly like the three club-naming ones added in
 * Version20261111120000 and for the same reasons: NULL is no override and the const answers,
 * a non-empty list wins, `[]` is never stored. Nothing is migrated — the 2,723 shipped names
 * stay in PHP as the floor, where they are diffable in a pull request and available to a
 * database that has never been seeded.
 *
 * JSON rather than JSONB, matching bbox and the club-naming columns, so migrations:diff
 * stays quiet. Nothing queries into these; they are read whole and handed to array_rand().
 */
final class Version20261112010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let an operator override a country\'s player/staff name pools, so a country with no pool does not need a deploy to get one.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_profile ADD first_names JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE country_profile ADD last_names JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_profile DROP last_names');
        $this->addSql('ALTER TABLE country_profile DROP first_names');
    }
}

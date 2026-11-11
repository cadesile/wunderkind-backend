<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let an operator author a country's club-naming content without a deploy.
 *
 * The locations dashboard's CONTENT readiness check has been telling operators that a country
 * is "missing from CountryContentRegistry" — a 2,364-line PHP file. Forty-six of the seventy-one
 * Country cases have no entry in it, so that blocker is the normal state of a new country and
 * the only fix was a code change.
 *
 * THREE NULLABLE COLUMNS, NOT A NEW TABLE. country_profile is already keyed on the same ISO-2
 * code the registry uses, is already the per-country operator surface, and already carries a
 * nullable json column (bbox) for exactly this shape of data. A 1:1 table against a 71-row one
 * would buy an entity, a repository, an FK and a create-the-row-if-missing branch on every
 * write — and a state the dashboard cannot reach, where a profile has no content row.
 *
 * NULL IS THE NORMAL STATE and no data is migrated. The 25 authored countries keep their PHP
 * consts, which remain the fallback; a row is written here only when an operator sets one.
 */
final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let an operator override a country\'s club-naming content, so the CONTENT readiness blocker is fixable without a deploy.';
    }

    public function up(Schema $schema): void
    {
        // JSON DEFAULT NULL matches how bbox was created on this table, so migrations:diff
        // stays quiet afterwards.
        $this->addSql('ALTER TABLE country_profile ADD prestige_suffixes JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE country_profile ADD generic_suffixes JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE country_profile ADD stadium_formats JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_profile DROP stadium_formats');
        $this->addSql('ALTER TABLE country_profile DROP generic_suffixes');
        $this->addSql('ALTER TABLE country_profile DROP prestige_suffixes');
    }
}

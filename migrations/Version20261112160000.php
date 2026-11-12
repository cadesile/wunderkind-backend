<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Give a country an economic scale, and name the one every other country is measured against.
 *
 * SHIPS INERT, AND THAT IS THE WHOLE DESIGN. `economy_factor_bp` defaults to 10000 — parity —
 * so every country is worth exactly what the baseline is worth until an operator says
 * otherwise. Running this migration cannot change a single generated wage, balance or ceiling.
 * Same compatibility mechanism as `starter_config.max_enrichment_requests_per_user` (0 =
 * unlimited): the backend ships ahead of the app, so a new knob arrives switched off and is
 * turned on deliberately, per country, from a form.
 *
 * BASIS POINTS RATHER THAN A FLOAT because this is one link in a country x tier chain that
 * ends in pence, and floats in that chain put two clubs in the same division a penny apart for
 * no reason a player can see. The chain stays integer and divides once, at the end.
 *
 * THE BASELINE FLAG IS GUARDED BY A PARTIAL UNIQUE INDEX, not by a service. Two baselines is
 * not a state anything can pick a winner for — every factor is normalised against the
 * baseline's own value, so a second one makes the entire scaling chain ambiguous, and an
 * ambiguity whose only symptom is slightly wrong money everywhere is one nobody traces back to
 * a flag. Partial because `false` is the normal state for 25 of 26 rows and PostgreSQL would
 * otherwise refuse the second non-baseline country.
 *
 * `postUp()` flags England, which is the baseline the economy was authored against. It is
 * written as a plain UPDATE rather than conditionally: a database with no EN row gets no
 * baseline, and EconomyScaleResolver treats "no baseline" as parity for everyone, which is the
 * same inert state the defaults give.
 */
final class Version20261112160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the per-country economy factor and the single baseline-country flag, both defaulting to no change.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_profile ADD is_economy_baseline BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE country_profile ADD economy_factor_bp INT DEFAULT 10000 NOT NULL');
        $this->addSql(
            'CREATE UNIQUE INDEX uq_country_profile_economy_baseline '
            . 'ON country_profile (is_economy_baseline) WHERE (is_economy_baseline = true)',
        );
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement(
            "UPDATE country_profile SET is_economy_baseline = true WHERE code = 'EN'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_country_profile_economy_baseline');
        $this->addSql('ALTER TABLE country_profile DROP economy_factor_bp');
        $this->addSql('ALTER TABLE country_profile DROP is_economy_baseline');
    }
}

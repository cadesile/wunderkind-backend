<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stadium build-price estimates: `stadium_estimate` and its split input snapshot.
 *
 * An estimate is a ROW rather than a calculation because a hold freezes a quoted price for
 * fifteen minutes, and "the price cannot move while you decide" is only true if the quote is a
 * thing the hold can point at. Recomputing on read would let a land revaluation or a coefficient
 * change move the figure mid-hold — exactly the promise the hold exists to make.
 *
 * TWO CONSEQUENCES OF THAT, both encoded here:
 *
 * - Estimates are SUPERSEDED, never deleted. `plot_hold.stadium_estimate_id` (next migration)
 *   is ON DELETE RESTRICT, so a live hold physically prevents its own quote being removed. The
 *   partial unique therefore keeps one CURRENT estimate per (location, model_version) and lets
 *   superseded rows accumulate. Predicate spelled exactly as pg_get_expr reports it.
 *
 * - `model_version` is part of that key. Bumping StadiumEstimateService::MODEL_VERSION makes the
 *   next refresh write a new current row per location instead of colliding with the old one,
 *   while outstanding holds keep the figure they were quoted.
 *
 * `stadium_estimate_input` is a split row for the reason schema.md gives for the seven that
 * already exist: ORM 3 decodes every mapped column on every read, and this blob is read only by
 * admin forensics while the estimate itself is read whenever a plot is quoted. It exists because
 * "believable realism" is a claim someone eventually audits — when a price looks wrong, only the
 * stored inputs AND coefficients can say whether the data or the model was at fault.
 */
final class Version20261103200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stadium estimates: stadium_estimate (superseded, never deleted) + stadium_estimate_input.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE stadium_estimate (
                id UUID NOT NULL,
                location_id UUID NOT NULL,
                pence BIGINT NOT NULL,
                model_version VARCHAR(20) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                valid_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                superseded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        // One CURRENT estimate per location per model version. Partial, because superseded rows
        // accumulate and must be allowed to repeat.
        $this->addSql('CREATE UNIQUE INDEX uq_stadium_estimate_current ON stadium_estimate (location_id, model_version) WHERE (superseded_at IS NULL)');
        $this->addSql('CREATE INDEX idx_stadium_estimate_location_created ON stadium_estimate (location_id, created_at)');
        $this->addSql('CREATE INDEX IDX_4D191DC764D218E ON stadium_estimate (location_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE stadium_estimate_input (
                id UUID NOT NULL,
                estimate_id UUID NOT NULL,
                inputs_json JSON NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_93DEA5F285F23082 ON stadium_estimate_input (estimate_id)');

        // CASCADE on both: an estimate is derived data about one location, and an input snapshot
        // is meaningless without the estimate it explains.
        $this->addSql('ALTER TABLE stadium_estimate ADD CONSTRAINT FK_4D191DC764D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stadium_estimate_input ADD CONSTRAINT FK_93DEA5F285F23082 FOREIGN KEY (estimate_id) REFERENCES stadium_estimate (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stadium_estimate_input DROP CONSTRAINT FK_93DEA5F285F23082');
        $this->addSql('ALTER TABLE stadium_estimate DROP CONSTRAINT FK_4D191DC764D218E');

        $this->addSql('DROP TABLE stadium_estimate_input');
        $this->addSql('DROP TABLE stadium_estimate');
    }
}

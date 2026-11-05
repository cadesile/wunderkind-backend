<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The setup flow's five endpoints: BUILD, FUND, setup-state and the onboarding quiz.
 *
 * Everything here backs `docs/api/setup-flow.md`'s "not built" list. Four pieces:
 *
 * 1. `scout.weekly_salary` — Scout had NO wage column at all, which is why a running-cost
 *    projection built from `GET /api/market/data` modelled every scout as free. DEFAULT 0,
 *    because a migration cannot invent a per-row figure, and 0 is then a visible "unpriced"
 *    sentinel rather than a plausible wrong number. **Run `app:backfill-scout-wages` after
 *    this** — it derives each wage from the experience already stored, deterministically, so
 *    backfilled and freshly-generated scouts agree.
 *
 * 2. `pool_config.scout_wage_min/max` — the range that backfill and generation interpolate
 *    across. Defaults (2500-7000 pence) match the £25-£70/week scale
 *    `docs/frontend-integration.md` already publishes for scouts, so the column arriving does
 *    not reprice the market against what the client has been showing.
 *
 * 3. `starter_config.quiz_bonus_multiplier` and the two funding columns. **The funding minimum
 *    defaults to 0, which means NO MINIMUM**, not "a minimum of zero" — the same asymmetry
 *    `max_enrichment_requests_per_user` has, and for the same reason: the endpoint goes live
 *    with this migration, and a default that started refusing stakes would be a schema change
 *    quietly changing behaviour. The multiplier defaults to 1.0, so a perfect quiz doubles the
 *    account's grant, which is the question the prototype note actually asked.
 *
 * 4. `setup_quiz_question` and `setup_quiz_answer`. The answer table's UNIQUE (user_id,
 *    question_id) is the "one attempt per account, ever" rule as a constraint rather than a
 *    check that could be raced — the server deliberately does not trust the client's 5s timer.
 *    `user_id` is ON DELETE CASCADE so account deletion needs no new DQL in
 *    AccountDeletionService, which leans on the database cascade for user-scoped tables.
 *
 * NO DATA IS REWRITTEN BY THIS MIGRATION. Every column arrives with a default that preserves
 * today's behaviour, and the only follow-up is the scout backfill above.
 *
 * Join-column indexes carry Doctrine's GENERATED names (IDX_F730E455A76ED395 and
 * IDX_F730E4551E27F6BF), and there are no `(DC2Type:uuid)` column comments: this Doctrine
 * version emits neither, so doing either leaves `doctrine:schema:validate` permanently out of
 * sync. Both were verified against `doctrine:schema:update --dump-sql` after applying this.
 */
final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Setup flow: scout wages, quiz tables, and the build/fund config';
    }

    public function up(Schema $schema): void
    {
        // 1 + 2 — scout wages.
        $this->addSql('ALTER TABLE scout ADD weekly_salary INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD scout_wage_min INT DEFAULT 2500 NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD scout_wage_max INT DEFAULT 7000 NOT NULL');

        // 3 — setup config. BIGINT on the pence column for the same reason
        // user_starting_balance_*_pence is: a 4-byte int caps at £21,474,836 and this ceiling is
        // admin-editable.
        $this->addSql('ALTER TABLE starter_config ADD quiz_bonus_multiplier DOUBLE PRECISION DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE starter_config ADD club_funding_minimum_pence BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE starter_config ADD club_funding_projection_weeks INT DEFAULT 13 NOT NULL');

        // 4 — the quiz.
        $this->addSql(<<<'SQL'
            CREATE TABLE setup_quiz_question (
                id UUID NOT NULL,
                prompt TEXT NOT NULL,
                options JSON NOT NULL,
                correct_index SMALLINT DEFAULT 0 NOT NULL,
                sort_order SMALLINT DEFAULT 0 NOT NULL,
                is_active BOOLEAN DEFAULT true NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE setup_quiz_answer (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                question_id UUID NOT NULL,
                chosen_index SMALLINT NOT NULL,
                is_correct BOOLEAN NOT NULL,
                answered_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        // DOCTRINE'S GENERATED NAMES, not names of our own. Verified with
        // `doctrine:schema:validate` — an explicit name is reported as a rename on every later
        // schema diff, which is the trap `club.home_location_id` taught and which this
        // migration tripped on its first draft.
        $this->addSql('CREATE INDEX IDX_F730E455A76ED395 ON setup_quiz_answer (user_id)');
        $this->addSql('CREATE INDEX IDX_F730E4551E27F6BF ON setup_quiz_answer (question_id)');

        // THE RULE, AS A CONSTRAINT. Two concurrent submissions for one (account, question)
        // cannot both land, so the quiz cannot be re-answered by racing it.
        $this->addSql(
            'CREATE UNIQUE INDEX uq_setup_quiz_answer_user_question ON setup_quiz_answer (user_id, question_id)',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE setup_quiz_answer
                ADD CONSTRAINT FK_F730E455A76ED395
                FOREIGN KEY (user_id) REFERENCES "user" (id)
                ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE setup_quiz_answer
                ADD CONSTRAINT FK_F730E4551E27F6BF
                FOREIGN KEY (question_id) REFERENCES setup_quiz_question (id)
                ON DELETE CASCADE NOT DEFERRABLE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE setup_quiz_answer');
        $this->addSql('DROP TABLE setup_quiz_question');

        $this->addSql('ALTER TABLE starter_config DROP club_funding_projection_weeks');
        $this->addSql('ALTER TABLE starter_config DROP club_funding_minimum_pence');
        $this->addSql('ALTER TABLE starter_config DROP quiz_bonus_multiplier');

        $this->addSql('ALTER TABLE pool_config DROP scout_wage_max');
        $this->addSql('ALTER TABLE pool_config DROP scout_wage_min');
        $this->addSql('ALTER TABLE scout DROP weekly_salary');
    }
}

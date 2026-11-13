<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Publish the deficit ladder in WEEKS OF WAGES, alongside the pence one.
 *
 * ── THE DEFECT THIS ANSWERS ───────────────────────────────────────────────────────────────
 *
 * `bankruptcyDeductionTiers` is flat pence while wages scale by country, tier, inflation and
 * wage model, so the two decouple the moment any of those moves — and nothing announces it.
 * Measured against the authored England ladder, the whole five-rung ladder is traversed in
 * **0.01 weeks** of unpaid wages at tier 1: the mechanic is "any deficit at all = maximum
 * penalty" rather than a graduated one. `app:economy:wage-table` check 5 reports it today.
 *
 * Weeks survive all four of those moves without re-authoring, because each moves the numerator
 * and the denominator together. `npcSolvencyThresholds` already works this way and is the
 * template the threshold review names.
 *
 * ── WHY A NEW KEY AND NOT A FIX TO THE OLD ONE ────────────────────────────────────────────
 *
 * `bankruptcyDeductionTiers` is read by shipped builds. It is untouched here — same name, same
 * type, same meaning, same values — and stays the ladder for `legacy`. This column is additive
 * and the app chooses which it reads.
 *
 * ── NULL IS THE INERT STATE, AND IT IS LOAD-BEARING ───────────────────────────────────────
 *
 * The app carries this ladder as a built-in constant, so **null means "use yours"**. No default
 * is authored here: a populated default would start overriding the app's constant on every box
 * the moment this migration ran, which is precisely the speculative publish
 * `.context/shared/api-backwards-compatibility.md` forbids. An operator opts in.
 *
 * Nullable with no DEFAULT also sidesteps the add-with-default-then-DROP-DEFAULT dance the
 * other JSON columns in this series needed: there is no existing row to backfill, because null
 * is already the value every row should have.
 *
 * Reversible: one drop.
 */
final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game_config.bankruptcy_deduction_weeks, nullable and unauthored, so the app keeps its own ladder until an operator opts in.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD bankruptcy_deduction_weeks JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP bankruptcy_deduction_weeks');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lowers `game_config.bond_decay_floor` from 50 to 20 so that neglected bonds actually decay.
 *
 * Owner ruling, 2026-11-20: asked whether a manager's bonds should hold the level they have
 * earned or fade when neglected — *"let them fade."*
 *
 * ⚠️ **AT 50 NEGLECT DECAY DID NOT HAPPEN AT ALL.** Only the portion of a bond ABOVE the floor
 * is at risk. Seeded bonds top out at 45 and organic growth adds 2 a week, so virtually every
 * bond the game produces sat at or below 50 and nothing was ever eroded — the relationship
 * graph only ratcheted upward, which is not what `decayNeglectedBonds` exists to do.
 *
 * ⚠️ **THE REASONING FOR THIS CHANGE WAS WRITTEN DOWN A WHILE AGO AND NEVER TOOK EFFECT**, and
 * that is the more useful lesson. `wunderkind-app`'s `DEFAULT_GAME_CONFIG.bondDecayFloor` was
 * lowered to 20 with a docblock explaining exactly the above — but that constant is only a
 * PRE-SYNC FALLBACK. The entity default and the live `game_config` row both stayed at 50, and a
 * synced value always beats the fallback, so the app's documentation has been describing the
 * losing side of a two-source disagreement ever since. Changing the entity alone would have
 * repeated the mistake: an entity default governs new installs only. Hence the UPDATE below.
 *
 * ⚠️ **THE UPDATE IS DELIBERATELY CONDITIONAL ON THE OLD DEFAULT.** `WHERE bond_decay_floor = 50`
 * moves boxes that simply never changed the value, and leaves alone any box where an operator
 * chose a figure on purpose. A blanket `SET bond_decay_floor = 20` would silently discard a
 * deliberate setting, and this column is admin-editable.
 *
 * The DB default is CHANGED, not dropped: `GameConfig::$bondDecayFloor` declares
 * `options: ['default' => 20]`, so the DB default is what keeps the ORM mapping in sync. See
 * `Version20261120100000`'s docblock — the rule is "the DB must match the entity", not "always
 * drop the default".
 *
 * ⚠️ Gameplay consequence, so it is not a surprise: bonds above 20 now erode by
 * `bond_positive_decay_per_week` (1) after `bond_neglect_weeks` (8) without an interaction. A
 * youth graduate's 50-60 settles at 20 rather than holding near 50, which reduces the steady
 * state of the manager-opinion bond bias by the same proportion. That interaction is recorded in
 * `wunderkind-docs/economy-loose-ends/a28-manager-opinion-noise.md`.
 */
final class Version20261120110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lower game_config.bond_decay_floor 50 -> 20 so neglected bonds actually decay.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ALTER bond_decay_floor SET DEFAULT 20');
        $this->addSql('UPDATE game_config SET bond_decay_floor = 20 WHERE bond_decay_floor = 50');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ALTER bond_decay_floor SET DEFAULT 50');
        $this->addSql('UPDATE game_config SET bond_decay_floor = 50 WHERE bond_decay_floor = 20');
    }
}

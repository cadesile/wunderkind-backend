<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repoints the setup tutorial's owner briefing at `ngs.tut.owner.bodyRole`.
 *
 * Data only. The key it replaces, `ngs.tut.owner.body`, read "You are the Chairman, not the
 * manager" — and the player is addressed as **Owner**, never Chairman, because Chairman is a
 * hireable staff role. It was the body of the first briefing a new player sees, immediately
 * before the briefing that has them hire a Chairman. The client cannot edit a bundled
 * catalogue value, so the fix there was a new key (`wunderkind-app@965aa5f3`); this points
 * the served payload at it.
 *
 * ⚠️ **Order with the client release is load-bearing.** `parseTutorial` DROPS an item whose
 * copy key it cannot resolve, so serving `ngs.tut.owner.bodyRole` to an app build that does
 * not carry it makes the owner briefing silently vanish. `965aa5f3` is on the `dev` OTA
 * channel already; this migration must not reach an environment whose client predates it.
 *
 * **Only touches a row still carrying the old key on that item**, so an admin who has since
 * edited the block keeps their version — the `@>` containment check in the WHERE clause is
 * what makes that true, and it is also what makes re-running this a no-op.
 *
 * No bare `?` anywhere: DBAL reads one as a positional bind placeholder, which is how
 * `Version20261102120000` failed a deploy earlier today. `@>` and `->>` are safe; the
 * key-exists operator is not, and `jsonb_exists()` is its function form.
 */
final class Version20261102130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Point the setup tutorial owner briefing at ngs.tut.owner.bodyRole (the player is the Owner, not the Chairman).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql($this->repoint('ngs.tut.owner.body', 'ngs.tut.owner.bodyRole'));
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->repoint('ngs.tut.owner.bodyRole', 'ngs.tut.owner.body'));
    }

    /**
     * Rebuilds the items array element by element rather than patching `items[0]`, because
     * the index is not part of the contract — an admin may reorder. `WITH ORDINALITY` plus
     * `ORDER BY` preserves the original order, which `jsonb_agg` would not otherwise promise.
     */
    private function repoint(string $from, string $to): string
    {
        return sprintf(
            <<<'SQL'
            UPDATE starter_config
               SET setup_tutorial_config = jsonb_set(
                       setup_tutorial_config::jsonb,
                       '{items}',
                       (
                           SELECT jsonb_agg(
                                      CASE WHEN element->>'id' = 'brief-owner'
                                           THEN jsonb_set(element, '{bodyKey}', '"%s"'::jsonb)
                                           ELSE element
                                      END
                                      ORDER BY ordinality
                                  )
                             FROM jsonb_array_elements(setup_tutorial_config::jsonb -> 'items')
                                  WITH ORDINALITY AS t(element, ordinality)
                       )
                   )::json
             WHERE jsonb_typeof(setup_tutorial_config::jsonb -> 'items') = 'array'
               AND setup_tutorial_config::jsonb -> 'items' @> '[{"id":"brief-owner","bodyKey":"%s"}]'::jsonb
            SQL,
            $to,
            $from,
        );
    }
}

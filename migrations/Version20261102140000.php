<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a competition entry outlive the club that made it: `competition_entrant.club_id`
 * becomes nullable, ON DELETE SET NULL instead of CASCADE.
 *
 * **The bug this fixes is visible in the game.** `competition_fixture`'s three entrant columns
 * are already ON DELETE SET NULL, so cascading the entrant row away with its club blanked that
 * club out of every bracket it had ever appeared in — a completed cup's opposition reverted to
 * "To be decided", scores included, leaving the player's own club alone in a bracket it had
 * apparently won against nobody. And it fired routinely rather than rarely: competitions are
 * filled by `CompetitionAutoFillService` with spoof clubs, and `app:clubs:purge-spoof` runs on
 * EVERY deploy in both workflows, so each deploy emptied out the finished cups.
 *
 * Keeping the row keeps `clubDisplayJson` — the frozen name, badge, kits and tier as they were
 * at registration — which is the whole of what a bracket renders. Nothing else moves: every
 * table hanging off the entrant (`competition_entrant_squad`, `competition_entrant_submission`,
 * `entrant_reward_claim`) still cascades from the entrant, so a deleted club no longer drags
 * them with it either, and the unique index on (active_competition_id, club_id) is unaffected
 * because Postgres treats NULLs as distinct — any number of orphaned entries may sit in one
 * competition.
 *
 * This does NOT repair brackets already damaged: those entrant rows are gone. The repair for
 * those is in CompetitionResponseBuilder::serializeFixtureSide(), which falls back to the
 * frozen club copy on `competition_result` (that table cascades from the FIXTURE, not the
 * club, so it survived).
 *
 * `down()` restores CASCADE, which is lossless in itself — but it cannot restore
 * non-nullability, because by then there may be rows with a null club_id that the original
 * constraint would reject. Those are deleted first, which is exactly what the old cascade
 * would have done to them.
 */
final class Version20261102140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'competition_entrant.club_id becomes nullable / ON DELETE SET NULL, so a deleted club no longer erases itself from finished brackets.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_entrant DROP CONSTRAINT FK_41CE6EBB61190A32');
        $this->addSql('ALTER TABLE competition_entrant ALTER COLUMN club_id DROP NOT NULL');
        $this->addSql('ALTER TABLE competition_entrant ADD CONSTRAINT FK_41CE6EBB61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM competition_entrant WHERE club_id IS NULL');
        $this->addSql('ALTER TABLE competition_entrant DROP CONSTRAINT FK_41CE6EBB61190A32');
        $this->addSql('ALTER TABLE competition_entrant ALTER COLUMN club_id SET NOT NULL');
        $this->addSql('ALTER TABLE competition_entrant ADD CONSTRAINT FK_41CE6EBB61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE NOT DEFERRABLE');
    }
}

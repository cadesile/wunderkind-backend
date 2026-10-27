<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Splits FriendlyChallenge's two inline squad snapshots the same way competition entrants
 * were split, and clears tournament and friendly instance data to do it.
 *
 * The fault was identical, just with a smaller multiplier. A challenge carried whole
 * snapshots — club, the XI, staff — in `challenger_snapshot_json` and
 * `acceptor_snapshot_json`, and GET /api/friendlies/challenges is public and lists every
 * open challenge, so rendering a board of club names, kits and badges decoded two full
 * squads per row. ORM 3 removed PARTIAL hydration, so no reader could opt out.
 *
 * After this:
 *   friendly_challenge         challenger_club_display_json (NOT NULL),
 *                              acceptor_club_display_json (NULL until accepted)
 *   friendly_challenge_squad   players_json, staff_json, club_engine_json, keyed by side
 *
 * `acceptor_club_display_json` stays nullable because the null is load-bearing: it is how
 * the service, the match-detail serializer and the board all recognise a challenge nobody
 * has taken yet.
 *
 * **This deletes data, by explicit choice.** Tournaments and friendlies are not live, and
 * clearing them was authorised specifically to keep this migration simple — so there is no
 * backfill SQL to get right, and the new NOT NULL column needs no synthetic default. What
 * goes is instance data only:
 *
 *   active_competition, competition_entrant, competition_entrant_squad,
 *   competition_fixture, competition_response_cache, competition_result,
 *   competition_round, entrant_reward_claim, friendly_challenge
 *
 * What stays is the admin-authored catalogue — competition_template, reward_template and
 * their join table. Those are configuration, not data, and nothing here touches them.
 *
 * The tables are named explicitly in one TRUNCATE rather than relying on CASCADE. They
 * reference each other, so they have to go together; naming them means a table someone adds
 * later that references one of these makes this migration fail loudly instead of having its
 * rows silently cascaded away.
 *
 * friendly_challenge_squad is created *before* the truncate and named in it. On a database
 * that reaches this migration normally the table does not exist yet, so the order would not
 * matter — but it does exist on any environment where `doctrine:schema:update` has already
 * built it from the entity (which is how the test database is maintained), and there the
 * original order failed on the very foreign key the truncate was meant to respect.
 */
final class Version20261028001500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split friendly_challenge snapshots into friendly_challenge_squad, clearing competition and friendly instance data.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE friendly_challenge_squad (
                id UUID NOT NULL,
                challenge_id UUID NOT NULL,
                side VARCHAR(16) NOT NULL,
                players_json JSON NOT NULL,
                staff_json JSON NOT NULL,
                club_engine_json JSON NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        // One row per side per challenge. FriendlyChallengeSquadRepository::upsertSide()
        // depends on this to find the existing row rather than creating a second one.
        $this->addSql('CREATE UNIQUE INDEX uq_friendly_challenge_squad_side ON friendly_challenge_squad (challenge_id, side)');
        $this->addSql(<<<'SQL'
            ALTER TABLE friendly_challenge_squad
                ADD CONSTRAINT fk_friendly_challenge_squad_challenge
                FOREIGN KEY (challenge_id) REFERENCES friendly_challenge (id)
                ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            TRUNCATE TABLE
                entrant_reward_claim,
                competition_result,
                competition_fixture,
                competition_entrant_squad,
                competition_entrant,
                competition_round,
                competition_response_cache,
                active_competition,
                friendly_challenge_squad,
                friendly_challenge
        SQL);

        // Safe as NOT NULL with no default because friendly_challenge is now empty.
        $this->addSql('ALTER TABLE friendly_challenge ADD challenger_club_display_json JSON NOT NULL');
        $this->addSql('ALTER TABLE friendly_challenge ADD acceptor_club_display_json JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE friendly_challenge DROP challenger_snapshot_json');
        $this->addSql('ALTER TABLE friendly_challenge DROP acceptor_snapshot_json');
    }

    public function down(Schema $schema): void
    {
        // Symmetrical, and equally destructive: the rows this would need to restore the old
        // columns from are the ones up() deleted.
        $this->addSql('TRUNCATE TABLE friendly_challenge_squad, friendly_challenge');

        $this->addSql('ALTER TABLE friendly_challenge ADD challenger_snapshot_json JSON NOT NULL');
        $this->addSql('ALTER TABLE friendly_challenge ADD acceptor_snapshot_json JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE friendly_challenge DROP challenger_club_display_json');
        $this->addSql('ALTER TABLE friendly_challenge DROP acceptor_club_display_json');

        $this->addSql('ALTER TABLE friendly_challenge_squad DROP CONSTRAINT fk_friendly_challenge_squad_challenge');
        $this->addSql('DROP TABLE friendly_challenge_squad');
    }
}

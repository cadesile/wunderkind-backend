<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Splits competition_entrant.snapshot_json into the two rows its readers actually wanted.
 *
 * The column held four things with four unrelated read profiles — club display data (read
 * on every public bracket render, for every entrant), the starting XI (read at resolve),
 * staff (read at resolve), and facilities (never read by anything) — and ORM 3 removed
 * PARTIAL hydration, so every reader decoded all of it. One public
 * GET /api/competitions/{id} therefore decoded up to 64 whole squads, which exhausted the
 * request memory limit; the same cause had already made the admin entrant index time out.
 *
 * After this migration:
 *  - competition_entrant.club_display_json holds the bounded, resolved display block.
 *  - competition_entrant_squad holds players_json, staff_json, and the three club keys only
 *    the match engine reads (cohesion, managerMotivation, lowUrgency).
 *  - facilities is dropped outright. Nothing ever read it.
 *
 * Existing rows are carried across in SQL rather than dropped. The display block is copied
 * as-is from `snapshot_json->'club'` rather than being re-resolved: the normaliser already
 * ran over it at registration, so it holds resolved keys plus whatever unrecognised keys the
 * client sent. That is a superset of the bounded block, so every read keeps working, and the
 * next resubmission narrows the row to the picked set. Re-resolving here would mean
 * reimplementing a 200-line PHP cascade in SQL for no gain.
 *
 * Down-migration note: reassembling snapshot_json loses `facilities` (deliberately dropped,
 * nothing read it) and, for any row written after this migration, the unrecognised client
 * keys the bounded block no longer carries. The structure round-trips; byte-identical
 * reconstruction of pre-migration rows does not.
 */
final class Version20261027230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split competition_entrant.snapshot_json into a bounded club display block plus competition_entrant_squad.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE competition_entrant_squad (
                id UUID NOT NULL,
                entrant_id UUID NOT NULL,
                players_json JSON NOT NULL,
                staff_json JSON NOT NULL,
                club_engine_json JSON NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        // Unique, not merely indexed: CompetitionRegistrationService::writeSquad() upserts
        // with ON CONFLICT (entrant_id), which requires a unique constraint to match against.
        $this->addSql('CREATE UNIQUE INDEX uq_competition_entrant_squad_entrant ON competition_entrant_squad (entrant_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE competition_entrant_squad
                ADD CONSTRAINT fk_competition_entrant_squad_entrant
                FOREIGN KEY (entrant_id) REFERENCES competition_entrant (id)
                ON DELETE CASCADE
        SQL);

        $this->addSql('ALTER TABLE competition_entrant ADD club_display_json JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_entrant ADD client_snapshot_at VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_entrant ADD squad_player_count SMALLINT DEFAULT 0 NOT NULL');

        // Carry existing registrations across. gen_random_uuid() is pgcrypto in older
        // Postgres but core since 13, and this project is on 16.
        $this->addSql(<<<'SQL'
            INSERT INTO competition_entrant_squad (id, entrant_id, players_json, staff_json, club_engine_json)
            SELECT gen_random_uuid(),
                   e.id,
                   CASE WHEN jsonb_typeof(e.snapshot_json::jsonb -> 'players') = 'array'
                        THEN (e.snapshot_json::jsonb -> 'players')::json
                        ELSE '[]'::json END,
                   CASE WHEN jsonb_typeof(e.snapshot_json::jsonb -> 'staff') = 'array'
                        THEN (e.snapshot_json::jsonb -> 'staff')::json
                        ELSE '[]'::json END,
                   COALESCE((
                       SELECT jsonb_object_agg(kv.key, kv.value)
                         FROM jsonb_each(e.snapshot_json::jsonb -> 'club') AS kv
                        WHERE kv.key IN ('cohesion', 'managerMotivation', 'lowUrgency')
                   ), '{}'::jsonb)::json
              FROM competition_entrant e
             WHERE e.snapshot_json IS NOT NULL
               AND jsonb_typeof(e.snapshot_json::jsonb -> 'club') = 'object'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE competition_entrant
               SET club_display_json = CASE WHEN jsonb_typeof(snapshot_json::jsonb -> 'club') = 'object'
                                            THEN (snapshot_json::jsonb -> 'club')::json
                                            ELSE '{}'::json END,
                   client_snapshot_at = LEFT(NULLIF(snapshot_json::jsonb ->> 'clientSnapshotAt', ''), 64),
                   squad_player_count = CASE WHEN jsonb_typeof(snapshot_json::jsonb -> 'players') = 'array'
                                             THEN jsonb_array_length(snapshot_json::jsonb -> 'players')
                                             ELSE 0 END
        SQL);

        // Only now that every row has been copied does the source column go, and the new one
        // become NOT NULL to match the entity.
        $this->addSql('ALTER TABLE competition_entrant ALTER COLUMN club_display_json SET NOT NULL');
        $this->addSql('ALTER TABLE competition_entrant DROP snapshot_json');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_entrant ADD snapshot_json JSON DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE competition_entrant e
               SET snapshot_json = jsonb_build_object(
                       'club', COALESCE(e.club_display_json::jsonb, '{}'::jsonb) || COALESCE(s.club_engine_json::jsonb, '{}'::jsonb),
                       'players', COALESCE(s.players_json::jsonb, '[]'::jsonb),
                       'staff', COALESCE(s.staff_json::jsonb, '[]'::jsonb),
                       'clientSnapshotAt', to_jsonb(e.client_snapshot_at)
                   )::json
              FROM competition_entrant_squad s
             WHERE s.entrant_id = e.id
        SQL);

        // Entrants with no squad row still need a structurally valid envelope.
        $this->addSql(<<<'SQL'
            UPDATE competition_entrant
               SET snapshot_json = jsonb_build_object(
                       'club', COALESCE(club_display_json::jsonb, '{}'::jsonb),
                       'players', '[]'::jsonb,
                       'staff', '[]'::jsonb,
                       'clientSnapshotAt', to_jsonb(client_snapshot_at)
                   )::json
             WHERE snapshot_json IS NULL
        SQL);

        $this->addSql('ALTER TABLE competition_entrant ALTER COLUMN snapshot_json SET NOT NULL');
        $this->addSql('ALTER TABLE competition_entrant DROP club_display_json');
        $this->addSql('ALTER TABLE competition_entrant DROP client_snapshot_at');
        $this->addSql('ALTER TABLE competition_entrant DROP squad_player_count');
        $this->addSql('ALTER TABLE competition_entrant_squad DROP CONSTRAINT fk_competition_entrant_squad_entrant');
        $this->addSql('DROP TABLE competition_entrant_squad');
    }
}

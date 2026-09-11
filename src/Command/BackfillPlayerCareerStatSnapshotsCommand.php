<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-time (but safe-to-repeat) backfill of PlayerCareerStatSnapshot from existing
 * sync_record history, so the 'all-time' leaderboard reconstruction has full history
 * from before this feature shipped, not just syncs received after it.
 *
 * sync_record is append-only and already carries payload.playerStats (a season-to-date
 * snapshot) plus a server-set server_timestamp for every sync ever received, so no data
 * has actually been lost by PlayerCareerStat overwriting in place — this command just
 * normalizes what is already there into the new indexed table.
 *
 * Deliberately a single set-based SQL statement, not a findBy()-and-hydrate loop: the
 * app:backfill-appearances command took that approach and OOM-killed a prod deploy on
 * ~36.5k rows (see docs/deploy/hetzner.md). This does the equivalent work entirely in
 * Postgres, with no PHP-side entity hydration, regardless of how large sync_record grows.
 *
 * Idempotent via the NOT EXISTS guard on sync_record_id — safe to run on every deploy,
 * since after the first run it only inserts snapshots for sync_records received since
 * the last run (normally zero extra work when a live sync already wrote its own row).
 */
#[AsCommand(
    name: 'app:backfill-player-career-stat-snapshots',
    description: 'Backfills PlayerCareerStatSnapshot history from existing sync_record.payload.playerStats',
)]
class BackfillPlayerCareerStatSnapshotsCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $inserted = $this->connection->executeStatement(<<<SQL
            INSERT INTO player_career_stat_snapshot
                (id, club_id, player_id, player_name, appearances, goals, assists, recorded_at, sync_record_id)
            SELECT
                gen_random_uuid(),
                sr.club_id,
                elem->>'playerId',
                COALESCE(NULLIF(elem->>'playerName', ''), elem->>'playerId'),
                COALESCE((elem->>'appearances')::int, 0),
                COALESCE((elem->>'goals')::int, 0),
                COALESCE((elem->>'assists')::int, 0),
                sr.server_timestamp,
                sr.id
            FROM sync_record sr
            CROSS JOIN LATERAL jsonb_array_elements(sr.payload::jsonb -> 'playerStats') elem
            WHERE jsonb_typeof(sr.payload::jsonb -> 'playerStats') = 'array'
              AND COALESCE(elem->>'playerId', '') != ''
              AND NOT EXISTS (
                  SELECT 1 FROM player_career_stat_snapshot s WHERE s.sync_record_id = sr.id
              )
            SQL);

        $io->success("Inserted {$inserted} player_career_stat_snapshot row(s).");

        return Command::SUCCESS;
    }
}

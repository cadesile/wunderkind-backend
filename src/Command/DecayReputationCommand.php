<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Enum\LeaderboardCategory;
use App\Repository\LeaderboardEntryRepository;
use App\Service\LeaderboardCalculationService;
use App\Service\ReputationDecayService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Charges every club the reputation it owes for the days it has gone without syncing
 * (ReputationDecayService::POINTS_PER_DAY per whole day).
 *
 * One command, two jobs, by design:
 *
 *  1. The nightly cron (see the Dockerfile crontab) — each run charges the one day
 *     that has passed since the last.
 *  2. The one-off backfill for an environment where decay has never run. There is
 *     deliberately no separate backfill command: the service charges *whole days
 *     since the club was last active*, so the very first run settles a club that has
 *     been silent for eight months in exactly the same arithmetic as a club silent
 *     for one day. Expect that first run to touch a large number of rows and to be
 *     the only run that moves most of them — abandoned clubs are the common case,
 *     not the exception.
 *
 * Safe to run repeatedly and safe to miss: the per-club anchor
 * (Club::$reputationDecayedAt) advances by exactly the days charged, so a second run
 * the same day costs nothing and a week of skipped runs is settled in one pass.
 *
 * Streams via toIterable() with periodic flush/clear rather than loading every club
 * at once — same posture as app:backfill-appearances, which OOM'd production on a
 * findAll(). Spoof clubs are skipped: they are admin-generated competition filler
 * (CompetitionSpoofEntrantService) that never syncs by definition, so decaying them
 * would just drive every one of them to zero for no gameplay reason.
 */
#[AsCommand(
    name: 'app:reputation:decay',
    description: 'Reduce club reputation by 5 points per day since each club last synced',
)]
final class DecayReputationCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReputationDecayService $decayService,
        private readonly LeaderboardEntryRepository $leaderboardEntryRepository,
        private readonly LeaderboardCalculationService $leaderboardCalculationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would change without writing anything. Use this before the '
                . 'first run on an environment that has never decayed — that run settles '
                . 'every dormant club at once and is much larger than a nightly one.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Stop after this many clubs. For sampling a large backfill, not for routine use.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        $now    = new \DateTimeImmutable();

        $qb = $this->em->getRepository(Club::class)
            ->createQueryBuilder('c')
            ->where('c.isSpoof = false')
            ->orderBy('c.createdAt', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        $seen        = 0;
        $decayed     = 0;
        $pointsLost  = 0;
        $flooredAtZero = 0;

        foreach ($qb->getQuery()->toIterable() as $club) {
            $seen++;

            $before = $club->getReputation();
            // Read before apply(): apply() advances the club's decay anchor, so
            // asking afterwards always reports 0 days.
            $days   = $this->decayService->dormantDays($club, $now);
            $lost   = $this->decayService->apply($club, $now);

            if ($lost > 0) {
                $decayed++;
                $pointsLost += $lost;

                $this->syncLeaderboardScore($club);

                if ($club->getReputation() === 0 && $before > 0) {
                    $flooredAtZero++;
                }

                if ($io->isVerbose()) {
                    $io->text(sprintf(
                        '  %s: %d -> %d (-%d over %d day(s))',
                        $club->getName(),
                        $before,
                        $club->getReputation(),
                        $lost,
                        $days,
                    ));
                }
            }

            if ($seen % self::BATCH_SIZE !== 0) {
                continue;
            }

            $this->persistBatch($dryRun);
        }

        $this->persistBatch($dryRun);

        // Ranks are derived from the scores just rewritten, and the public read is
        // cache-first — neither updates itself. Skipped on a dry run, which wrote nothing.
        if (!$dryRun && $decayed > 0) {
            $this->leaderboardCalculationService->invalidate(LeaderboardCategory::CLUB_REPUTATION);

            foreach (['all-time', (new \DateTimeImmutable())->format('o-\WW')] as $period) {
                $this->leaderboardCalculationService->recalculate(LeaderboardCategory::CLUB_REPUTATION, $period);
            }
        }

        $io->definitionList(
            ['Clubs examined' => $seen],
            ['Clubs decayed' => $decayed],
            ['Reputation removed' => $pointsLost],
            ['Hit the zero floor' => $flooredAtZero],
            ['Mode' => $dryRun ? 'DRY RUN — nothing written' : 'applied'],
        );

        $io->success($dryRun ? 'Dry run complete.' : 'Reputation decay applied.');

        return Command::SUCCESS;
    }

    /**
     * Writes the club's new reputation through to its existing club_reputation
     * leaderboard entries.
     *
     * Necessary because club_reputation is a NON-aggregate category: its score is
     * upserted only by SyncService at sync time, and app:leaderboards:generate just
     * re-ranks whatever is already stored (see LeaderboardCalculationService::recalculate()).
     * Without this, a dormant club's decayed reputation would never reach the board —
     * it would sit on its last-synced score indefinitely, which is precisely the case
     * decay exists to correct.
     *
     * Only existing periods are touched, never created: a club that has not synced this
     * ISO week has no entry for it, and adding one would put a dormant club onto a
     * weekly board it never appeared on.
     */
    private function syncLeaderboardScore(Club $club): void
    {
        $score = $club->getReputation();

        foreach ($this->leaderboardEntryRepository->findAllForClubAndCategory($club, LeaderboardCategory::CLUB_REPUTATION) as $entry) {
            $entry->setScore($score);
        }
    }

    /**
     * On a dry run the EntityManager is cleared WITHOUT flushing, so the in-memory
     * changes are discarded rather than written. Clearing either way is what keeps
     * memory flat across a backfill of every club in the database.
     */
    private function persistBatch(bool $dryRun): void
    {
        if (!$dryRun) {
            $this->em->flush();
        }

        $this->em->clear();
    }
}

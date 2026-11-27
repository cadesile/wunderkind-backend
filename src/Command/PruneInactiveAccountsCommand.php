<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\GameConfigRepository;
use App\Service\InactiveAccountPruneService;
use App\Service\Notification\OwnerNoticeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes accounts that never played and have not been seen for a long time, after warning
 * them by email (Courtney, 2026-11-27: 90 days unseen, 14-day warning).
 *
 * WHO: see InactiveAccountPruneService. Briefly, no club, sale, ledger, plot or leaderboard
 * history ever, not an admin/debug/spoof/protected account, and unseen for the configured days.
 * Someone who played and took a break is never a candidate.
 *
 * TWO KEYS TO DELETE. Without --live this is always a dry run that changes nothing. With
 * --live it still only acts when Game Config "inactive account cleanup" is set to live; 'off'
 * makes the run a no-op and 'dry_run' reports only. The nightly cron passes --live, so the Game
 * Config switch is the per-environment control: dev and prod have separate databases.
 *
 * WARN FIRST, ALWAYS. An account is deleted only if it has a warning row whose delete_after has
 * passed and it has not been seen since the warning. The email is sent before the row is
 * written, so a failed send leaves no row and the account cannot be deleted unwarned. Being
 * seen again (login, device check-in) drops the warning, so a later lapse starts over.
 *
 * BOUNDED. At most max-per-run warnings and max-per-run deletions per run, and a live run
 * refuses to do anything when the candidate pool is larger than --abort-above, because a
 * sudden jump means a rule or a table changed and should be looked at first.
 */
#[AsCommand(
    name: 'app:users:prune-inactive',
    description: 'Warn, then delete, accounts that never played and have been inactive for the configured days.',
)]
class PruneInactiveAccountsCommand extends Command
{
    private const DEFAULT_ABORT_ABOVE = 1000;

    public function __construct(
        private readonly InactiveAccountPruneService $pruneService,
        private readonly GameConfigRepository $configRepository,
        private readonly OwnerNoticeService $noticeService,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('live', null, InputOption::VALUE_NONE, 'Actually warn and delete (also needs Game Config mode = live). Without it this is a dry run.')
            ->addOption('abort-above', null, InputOption::VALUE_REQUIRED, 'Refuse a live run when more candidates than this are found', (string) self::DEFAULT_ABORT_ABOVE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $config = $this->configRepository->getConfig();
        $mode   = $config->getInactiveAccountPruneMode();

        if ($input->getOption('live') && $mode === 'off') {
            $io->note('Inactive account cleanup is OFF in Game Config. Nothing done.');

            return Command::SUCCESS;
        }

        $live       = $input->getOption('live') && $mode === 'live';
        $days       = $config->getInactiveAccountPruneDays();
        $warnDays   = $config->getInactiveAccountPruneWarningDays();
        $maxPerRun  = $config->getInactiveAccountPruneMaxPerRun();
        $protected  = $config->getInactiveAccountPruneProtectedEmailList();
        $abortAbove = max(0, (int) $input->getOption('abort-above'));

        $now    = new \DateTimeImmutable();
        $cutoff = $now->modify(sprintf('-%d days', $days));

        $candidates = $this->pruneService->findCandidates($cutoff, $protected);
        $stale      = $this->pruneService->findStaleWarnings(array_column($candidates, 'id'));

        $io->writeln(sprintf(
            '%s run: unseen for %d days (before %s), %d-day warning, max %d per run.',
            $live ? 'LIVE' : 'DRY',
            $days,
            $cutoff->format('Y-m-d'),
            $warnDays,
            $maxPerRun,
        ));

        if ($live && count($candidates) > $abortAbove) {
            $io->error(sprintf(
                '%d candidates is more than --abort-above=%d. Nothing done. Check the rule with a dry run, then re-run with a higher --abort-above if it is right.',
                count($candidates),
                $abortAbove,
            ));

            return Command::FAILURE;
        }

        $toWarn = $due = $waiting = [];
        foreach ($candidates as $row) {
            if ($row['warned_at'] === null) {
                $toWarn[] = $row;
            } elseif (new \DateTimeImmutable($row['delete_after']) <= $now
                && new \DateTimeImmutable($row['last_seen_at']) <= new \DateTimeImmutable($row['warned_at'])) {
                $due[] = $row;
            } else {
                $waiting[] = $row;
            }
        }

        $toWarn = array_slice($toWarn, 0, $maxPerRun);
        $due    = array_slice($due, 0, $maxPerRun);
        $table  = [];

        // 1. Re-arm: warnings for accounts seen again, or no longer candidates.
        if ($live) {
            $this->pruneService->clearWarnings(array_column($stale, 'user_id'));
        }
        foreach ($stale as $row) {
            $table[] = [$row['email'], '-', '-', $live ? 'warning cleared (seen again)' : 'would clear warning (seen again)'];
        }

        // 2. Delete the accounts whose warning has run out.
        $deleted = [];
        if ($live && $due !== []) {
            $deleted = $this->pruneService->deleteAccounts(array_column($due, 'id'), $cutoff, $protected);
        }
        foreach ($due as $row) {
            $outcome = !$live ? 'would delete' : (in_array($row['email'], $deleted, true) ? 'deleted' : 'skipped (changed since scan)');
            $table[] = [$row['email'], substr($row['last_seen_at'], 0, 10), substr((string) $row['warned_at'], 0, 10), $outcome];
        }

        // 3. Warn new candidates: email first, row second (see class docblock).
        $warned = $failed = 0;
        $deleteAfter = $now->modify(sprintf('+%d days', $warnDays));
        foreach ($toWarn as $row) {
            if (!$live) {
                $table[] = [$row['email'], substr($row['last_seen_at'], 0, 10), '-', 'would warn'];
                continue;
            }

            try {
                $user = $this->em->find(User::class, $row['id']);
                if ($user === null) {
                    continue;
                }
                $this->noticeService->sendEmail($user, ...$this->warningEmail($deleteAfter, $days));
                $this->pruneService->recordWarning($row['id'], $now, $deleteAfter);
                $warned++;
                $table[] = [$row['email'], substr($row['last_seen_at'], 0, 10), $now->format('Y-m-d'), 'warned'];
            } catch (\Throwable $e) {
                // No row was written, so this account cannot be deleted until a warning lands.
                $failed++;
                $io->warning(sprintf('Could not warn %s: %s', $row['email'], $e->getMessage()));
            }
        }

        if ($table !== []) {
            $io->table(['Email', 'Last seen', 'Warned', 'Outcome'], $table);
        }

        $io->definitionList(
            ['Candidates' => count($candidates)],
            [$live ? 'Warned' : 'Would warn' => $live ? $warned : count($toWarn)],
            ['Waiting out warning' => count($waiting)],
            [$live ? 'Deleted' : 'Would delete' => $live ? count($deleted) : count($due)],
            [$live ? 'Warnings cleared' : 'Would clear warnings' => count($stale)],
            ['Failed to warn' => $failed],
        );

        if (!$live) {
            $io->note($input->getOption('live')
                ? 'Game Config mode is dry_run, so nothing was sent or deleted.'
                : 'Dry run: nothing was sent or deleted. Pass --live (with Game Config mode = live) to act.');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return array{0: string, 1: string} subject, body */
    private function warningEmail(\DateTimeImmutable $deleteAfter, int $days): array
    {
        $date = $deleteAfter->format('j F Y');

        return [
            sprintf('Your Build My Club account will be deleted on %s', $date),
            sprintf(
                "Hello,\n\nYou created a Build My Club account but haven't started a club, and we haven't seen you for over %d days. "
                . "To keep things tidy, unused accounts like this are deleted.\n\n"
                . "If you'd like to keep your account, just sign in to the app before %s and it will stay.\n\n"
                . "If you're happy for it to go, you don't need to do anything.\n\nThe Build My Club team",
                $days,
                $date,
            ),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Location\PlotClaim;
use App\Enum\Location\DormancyWarningStage;
use App\Repository\Location\ClubDormancyWarningRepository;
use App\Repository\Location\PlotClaimRepository;
use App\Service\InboxService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Warn players whose plots are about to be reclaimed for inactivity.
 *
 * MUST RUN BEFORE app:plots:dormancy-reclaim, and the reclaim enforces that: it refuses to take
 * a plot that has no SENT one-day warning for the current dormancy cycle. So a missed run here
 * delays a reclaim rather than causing an unwarned one.
 *
 * THE ACTIVITY CLOCK IS NOT DEFINED HERE. It lives in exactly one place —
 * PlotClaimRepository::findDormantClaims(), as COALESCE(last_synced_at, created_at) — because a
 * week advancing reaches the backend only as a sync, so "last synced" and "last played" are the
 * same event (SyncService sets lastSyncedWeek and lastSyncedAt on adjacent lines, and
 * ReputationDecayService already treats it as the activity clock). A second definition here
 * would be a second thing to drift.
 *
 * ROW FIRST, THEN SEND, THEN STAMP. claimWarning() inserts with ON CONFLICT DO NOTHING, which is
 * what makes the dedupe atomic across overlapping cron ticks; only the caller that won the
 * insert sends. A crash between the send and the stamp risks one duplicate warning on the next
 * pass, which is much better than the reverse order's risk of a silent permanent skip.
 *
 * CLAIMS WITH NO CLUB ARE NEVER WARNED OR RECLAIMED, because they have no activity clock —
 * findDormantClaims() joins club. Land bought and never built on is therefore held
 * indefinitely. That is a deliberate consequence of letting land be bought before a club
 * exists, not an oversight, but it is a product question worth revisiting.
 */
#[AsCommand(
    name: 'app:plots:dormancy-warn',
    description: 'Warn owners whose clubs have gone quiet that their plot is about to be reclaimed.',
)]
class DormancyWarnCommand extends Command
{
    public function __construct(
        private readonly PlotClaimRepository           $claimRepository,
        private readonly ClubDormancyWarningRepository $warningRepository,
        private readonly InboxService                  $inbox,
        private readonly EntityManagerInterface        $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum claims to consider', '500')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be sent and send nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit  = max(1, (int) $input->getOption('limit'));

        $now = new \DateTimeImmutable();

        // The earliest stage's threshold, so one query covers both buckets.
        $earliest = DormancyWarningStage::N_MINUS_7->triggersAfterDays();
        $cutoff   = $now->modify(sprintf('-%d days', $earliest));

        $rows = $this->claimRepository->findDormantClaims($cutoff, $limit);

        if ($rows === []) {
            $io->success('No clubs are inactive enough to warn.');

            return Command::SUCCESS;
        }

        $sent = $alreadyWarned = $tooEarly = $failed = 0;
        $table = [];

        foreach ($rows as $row) {
            $lastActive   = new \DateTimeImmutable((string) $row['last_active_at']);
            $daysInactive = (int) $lastActive->diff($now)->days;
            $stage        = DormancyWarningStage::forDaysInactive($daysInactive);

            if ($stage === null) {
                $tooEarly++;
                continue;
            }

            // The cycle key: the date of the activity timestamp this dormancy run was computed
            // from. A player who comes back and lapses again gets a NEW key and therefore a new
            // set of warnings — see ClubDormancyWarning's docblock.
            $cycleKey = $lastActive->format('Y-m-d');

            $claim = $this->claimRepository->find((string) $row['claim_id']);

            if ($claim === null || !$claim->isActive() || $claim->getClub() === null) {
                continue;
            }

            if ($dryRun) {
                $table[] = [$row['town'], $claim->getClub()->getName(), $daysInactive, $stage->label(), 'would send'];
                $sent++;
                continue;
            }

            $warningId = $this->warningRepository->claimWarning($claim, $stage, $cycleKey, $now);

            if ($warningId === null) {
                // Someone already warned this cycle and stage. Not an error — it is the dedupe
                // working, and it is what makes this command safe to run on an overlapping tick.
                $alreadyWarned++;
                continue;
            }

            try {
                $message = $this->deliver($claim, $stage, $daysInactive);
                $this->warningRepository->markSent($warningId, (string) $message->getId(), $now);
                $sent++;
                $table[] = [$row['town'], $claim->getClub()->getName(), $daysInactive, $stage->label(), 'sent'];
            } catch (\Throwable $e) {
                // The row stays unstamped, so the next pass retries the SEND without
                // re-inserting. Deliberately does not abort the run — one undeliverable inbox
                // must not cost every other player their warning.
                $failed++;
                $io->warning(sprintf('Could not warn %s: %s', $row['town'], $e->getMessage()));
            }
        }

        if ($table !== []) {
            $io->table(['Town', 'Club', 'Days inactive', 'Stage', 'Outcome'], $table);
        }

        $io->definitionList(
            ['Considered' => count($rows)],
            [$dryRun ? 'Would send' : 'Sent' => $sent],
            ['Already warned this cycle' => $alreadyWarned],
            ['Not yet due' => $tooEarly],
            ['Failed' => $failed],
        );

        if ($dryRun) {
            $io->note('Dry run — nothing was sent and no warning rows were written.');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function deliver(PlotClaim $claim, DormancyWarningStage $stage, int $daysInactive): \App\Entity\InboxMessage
    {
        $town = $claim->getLocation()->getTown();
        $days = $stage->daysRemaining();

        return $this->inbox->sendSystemNotification(
            $claim->getClub(),
            sprintf('Your ground at %s — %d day%s left', $town, $days, $days === 1 ? '' : 's'),
            sprintf(
                'Your club has been inactive for %d days. If nothing changes, the plot at %s will be '
                . 'released in %d day%s and you will be credited for the land, the ground and the club. '
                . 'Playing a single week keeps it.',
                $daysInactive,
                $town,
                $days,
                $days === 1 ? '' : 's',
            ),
            [
                'kind'          => 'dormancy_warning',
                'stage'         => $stage->value,
                'claimId'       => (string) $claim->getId(),
                'locationId'    => (string) $claim->getLocation()->getId(),
                'daysRemaining' => $days,
            ],
        );
    }
}

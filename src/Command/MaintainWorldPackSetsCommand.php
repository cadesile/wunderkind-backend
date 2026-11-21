<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\WorldPack\WorldPackSetService;
use App\Service\WorldPack\WorldPackSetTopUpService;
use App\Service\WorldPackCacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\WorldPack\TopUpWorldPackSetMessage;

/**
 * The hourly sweep that keeps every live country's world pack set buffer stocked, and prunes
 * the payloads of sets claimed long ago.
 *
 * ── Why a sweep exists at all, when every init dispatches a top-up ───────────────────────
 *
 * Because that dispatch is best-effort. InitializeController wraps it so a transport failure
 * can never turn a successful initialisation into an error, which means a lost message is a
 * country quietly one set short with nothing to notice it. This is the backstop, and it is
 * also what brings every country to target after a deploy, after a WORLD_PACK_VERSION bump
 * (which makes every existing set unclaimable at once), and after any period where generation
 * was failing.
 *
 * It QUEUES rather than generates, so one slow country cannot stall the sweep across the
 * others — the same division of labour as StarterPoolAuditService, which decides and queues
 * while its handler does the work. `--sync` builds inline instead, for operators who want to
 * watch it happen.
 *
 * Live countries come from `CountryProfileRepository::liveCountryCodes()` — the operator's own
 * "this country is playable" axis — not from the Country enum's generation-capable list, which
 * is broader, and not from `StarterConfig::$enabledCountries`, which is effectively dead.
 *
 * ── The prune ────────────────────────────────────────────────────────────────────────────
 *
 * Folded in here rather than given its own cron, because it is the same "keep the table
 * sane" job and runs on the same cadence. It drops the tier payloads of sets claimed more
 * than `StarterConfig::$worldPackClaimedRetentionDays` ago, keeping the set row as the
 * record of which club was served which generation. Without it the table grows by a whole
 * country's worth of large JSON per new game, forever.
 */
#[AsCommand(
    name: 'app:worldpack:maintain',
    description: 'Top every live country up to its world pack set target, and prune long-claimed payloads.',
)]
class MaintainWorldPackSetsCommand extends Command
{
    public function __construct(
        private readonly CountryProfileRepository $countryProfileRepository,
        private readonly StarterConfigRepository  $starterConfigRepository,
        private readonly WorldPackSetService      $setService,
        private readonly WorldPackSetTopUpService $topUpService,
        private readonly WorldPackCacheService    $cacheService,
        private readonly MessageBusInterface      $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Build inline instead of queueing. Slow; for operators watching.')
            ->addOption('no-prune', null, InputOption::VALUE_NONE, 'Skip the retention prune.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen and change nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $sync    = (bool) $input->getOption('sync');
        $dryRun  = (bool) $input->getOption('dry-run');
        $target  = $this->topUpService->targetSetCount();

        $countries = $this->countryProfileRepository->liveCountryCodes();

        $io->title(sprintf('World pack sets — maintaining %d live country(ies), target %d', count($countries), $target));

        if ($countries === []) {
            // NOT an early return. The prune below is independent of which countries are live:
            // claimed sets accumulate from every game ever initialised, and a box whose
            // profiles have since been taken out of LIVE would otherwise keep their payloads
            // forever. The top-up half is the part that has nothing to do.
            $io->warning(
                'No country profile is LIVE, so there is nothing to top up. '
                . 'Set a profile LIVE to make a country playable. Pruning anyway.'
            );
        }

        $rows     = [];
        $queued   = 0;
        $built    = 0;
        $reaped   = 0;
        $starved  = [];

        foreach ($countries as $country) {
            // Reap first, so the deficit below is read against stock that something is
            // actually working on. A set is persisted BUILDING before its first tier is
            // built, so a worker lost mid-build — fatal, OOM, container recreate on deploy —
            // leaves a row that deficit() counts as stock and nothing will ever finish. This
            // sweep is the backstop for exactly that, and it could not see it before.
            if (!$dryRun) {
                $reaped += $this->setService->reapStaleBuilding($country);
            }

            $stock   = $this->setService->stock($country, $target);
            $deficit = $stock['deficit'];
            $label   = sprintf('%d/%d claimable', $stock['claimable'], $stock['target'])
                . ($stock['building'] > 0 ? sprintf(' (+%d building)', $stock['building']) : '');

            if ($deficit <= 0) {
                // 🔴 "At target" used to cover this case too, which made the one job that
                // exists to notice a country running dry the last place it would show up. A
                // country with nothing claimable is not stocked, however many builds are
                // counted in flight.
                if ($stock['starved']) {
                    $starved[] = $country;
                    $rows[]    = [$country, $label, 'STARVED — nothing queued'];
                    continue;
                }

                $rows[] = [$country, $label, '-'];
                continue;
            }

            if ($dryRun) {
                $rows[] = [$country, $label, "would queue {$deficit}"];
                continue;
            }

            if ($sync) {
                $made = $this->topUpService->topUp($country, $deficit);
                $built += count($made);
                $rows[] = [$country, $label, sprintf('built %d', count($made))];
                continue;
            }

            $this->messageBus->dispatch(new TopUpWorldPackSetMessage($country, $deficit));
            $queued++;
            $rows[] = [$country, $label, "queued {$deficit}"];
        }

        if ($rows !== []) {
            $io->table(['Country', 'Stock', 'Action'], $rows);
        }

        if ($reaped > 0) {
            // Loud on purpose: every one of these is a build that died without saying so, and
            // for up to thirty minutes it made this country look stocked to every top-up path.
            $io->warning(sprintf(
                'Reaped %d stranded BUILDING set(s) — marked FAILED with a reason. '
                . 'Each was a build no worker finished (PHP fatal, OOM kill, or a container '
                . 'restart mid-generation).',
                $reaped,
            ));
        }

        if ($starved !== []) {
            $io->error(sprintf(
                'STARVED: %s — no claimable set, yet counted at target by builds in flight, so '
                . 'nothing was queued. A device cannot start a game in these countries. If the '
                . 'builds are not genuinely running, regenerate the country.',
                implode(', ', $starved),
            ));
        }

        if (!$input->getOption('no-prune') && !$dryRun) {
            $days   = max(1, $this->starterConfigRepository->getConfig()->getWorldPackClaimedRetentionDays());
            $before = new \DateTimeImmutable("-{$days} days");
            $pruned = $this->cacheService->prunePayloadsClaimedBefore($before);

            $io->text(sprintf(
                'Pruned %d tier payload row(s) from sets claimed before %s (retention: %d day(s)). Set rows kept.',
                $pruned,
                $before->format('Y-m-d H:i'),
                $days,
            ));
        }

        if ($dryRun) {
            $io->success('Dry run — nothing changed.');
        } else {
            $io->success(sprintf(
                'Queued %d country(ies), built %d set(s) inline, reaped %d stranded build(s).',
                $queued,
                $built,
                $reaped,
            ));
        }

        // A starved country is a failure of the thing this command exists to guarantee, so the
        // exit code says so — the cron wrapper logs a FAILED line on a non-zero exit, which is
        // the only channel an unattended sweep has.
        return $starved === [] ? Command::SUCCESS : Command::FAILURE;
    }
}

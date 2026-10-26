<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\StarterPoolAuditService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron entry point: run the starter-pack check across every enabled country and queue the
 * pool generation that would fix whatever came back short.
 *
 * The per-country verdict is `app:starter-pack:check`'s, unchanged — this command adds the
 * sweep and the queueing, not a second opinion about pool health. It queues chunks onto
 * the `pool` transport rather than generating inline: a cold top-up for one country is
 * ~20s of DB-heavy work, and doing that for 25 countries inside a single cron tick is how
 * a box falls over. See StarterPoolAuditService for the three guards that keep an
 * unattended sweep from stacking or from grinding at a shortfall generation cannot fix.
 *
 * Exit code reports whether the **sweep** worked, not whether the pool is healthy — a
 * country needing a top-up is the normal case this exists to handle, so it is not a
 * failure. Blockers are printed as warnings and listed in the summary.
 */
#[AsCommand(
    name: 'app:starter-pool:audit',
    description: 'Check every enabled country for starter-pack supply and queue pool top-ups for any that fall short.',
)]
class AuditStarterPoolCommand extends Command
{
    public function __construct(
        private readonly StarterPoolAuditService $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('country',     null, InputOption::VALUE_REQUIRED, 'Audit one country instead of every enabled one')
            ->addOption('dry-run',     null, InputOption::VALUE_NONE,     'Report what would be queued, and queue nothing')
            ->addOption('force',       null, InputOption::VALUE_NONE,     'Sweep even while the pool queue still has undrained chunks')
            ->addOption('min-players', null, InputOption::VALUE_REQUIRED, 'Per-nationality player floor to top up to',      StarterPoolAuditService::DEFAULT_MIN_PLAYERS)
            ->addOption('min-staff',   null, InputOption::VALUE_REQUIRED, 'Per-nationality floor per staff role',           StarterPoolAuditService::DEFAULT_MIN_STAFF_PER_ROLE)
            ->addOption('min-scouts',  null, InputOption::VALUE_REQUIRED, 'Per-nationality scout floor',                    StarterPoolAuditService::DEFAULT_MIN_SCOUTS)
            ->addOption('chunk',       null, InputOption::VALUE_REQUIRED, 'Entities generated per queued message',          StarterPoolAuditService::DEFAULT_CHUNK);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $countryOption = $input->getOption('country');
        $countries     = $countryOption !== null
            ? [strtoupper(trim((string) $countryOption))]
            : $this->audit->activeCountries();

        if ($countries === []) {
            $io->warning('No enabled countries — StarterConfig::enabledCountries is empty. Nothing to audit.');

            return Command::SUCCESS;
        }

        if (!$dryRun && !$input->getOption('force') && $this->audit->queueIsBusy()) {
            $io->note('Pool queue still has undrained chunks from a previous sweep — skipping. Pass --force to sweep anyway.');

            return Command::SUCCESS;
        }

        $minimums = [
            'players' => (int) $input->getOption('min-players'),
            'staff'   => (int) $input->getOption('min-staff'),
            'scouts'  => (int) $input->getOption('min-scouts'),
        ];
        $chunk = (int) $input->getOption('chunk');

        $io->title(sprintf('Starter pool audit — %d countr%s', count($countries), count($countries) === 1 ? 'y' : 'ies'));

        $rows           = [];
        $blockers       = [];
        $totalMessages  = 0;
        $totalEntities  = 0;

        foreach ($countries as $code) {
            $plan = $this->audit->auditCountry($code, $minimums, $chunk);

            $queued = 0;
            if (!$dryRun && $plan['needs'] !== []) {
                $queued = $this->audit->queue($plan);
            }

            $totalMessages += $dryRun ? $plan['messages'] : $queued;
            $totalEntities += $plan['entities'];

            foreach ($plan['blockers'] as $blocker) {
                $blockers[] = sprintf('[%s] %s', $plan['country'], $blocker);
            }

            $rows[] = [
                $plan['country'],
                $plan['nationality'] ?? '—',
                $this->verdictCell($plan['verdict']),
                $plan['needs'] === [] ? '—' : $this->needsCell($plan['needs']),
                $plan['entities'] === 0 ? '—' : (string) $plan['entities'],
                $dryRun ? ($plan['messages'] === 0 ? '—' : $plan['messages'] . ' (dry run)') : (string) $queued,
                $plan['blockers'] === [] ? '' : count($plan['blockers']),
            ];
        }

        $io->table(['Country', 'Nationality', 'Verdict', 'Needs', 'Entities', 'Queued', 'Blocked'], $rows);

        if ($blockers !== []) {
            $io->section('Blockers — generation cannot fix these');
            foreach ($blockers as $blocker) {
                $io->writeln('  <fg=yellow;options=bold>!</> ' . $blocker);
            }
            $io->newLine();
        }

        $summary = sprintf(
            '%d message%s %s for %d entit%s across %d countr%s.',
            $totalMessages,
            $totalMessages === 1 ? '' : 's',
            $dryRun ? 'would be queued' : 'queued',
            $totalEntities,
            $totalEntities === 1 ? 'y' : 'ies',
            count($countries),
            count($countries) === 1 ? 'y' : 'ies',
        );

        if ($totalMessages === 0 && $blockers === []) {
            $io->success('Every audited country can serve a full starter pack. Nothing queued.');
        } elseif ($dryRun) {
            $io->note($summary);
        } else {
            $io->success($summary . ' Drained by docker/pool-consume.sh.');
        }

        return Command::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $needs */
    private function needsCell(array $needs): string
    {
        return implode(', ', array_map(
            static fn (array $need): string => sprintf('%s +%d', $need['label'], $need['deficit']),
            $needs,
        ));
    }

    private function verdictCell(string $verdict): string
    {
        return match ($verdict) {
            'ok'       => '<fg=green>ok</>',
            'degraded' => '<fg=yellow>degraded</>',
            default    => '<fg=red;options=bold>blocked</>',
        };
    }
}

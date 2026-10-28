<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Competition\CompetitionHealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports whether competition round processing is keeping up. Read-only.
 *
 * Answers the question the benchmark could not: the claim lease, the consumer's time and
 * memory limits and the one-minute cron cadence were all set from measurements on one box,
 * and nothing in production said whether those settings still held. Run it by hand against
 * prod while rounds are resolving — it takes no locks and writes nothing.
 *
 * `--strict` exits 1 when anything is wrong, so it can be a cron or monitoring check rather
 * than something someone has to remember to look at. `--json` prints the raw snapshot for
 * scraping.
 */
#[AsCommand(
    name: 'app:competition:health',
    description: 'Queue depth, consumer lag, stale round claims and cache coverage for competition processing',
)]
class CompetitionHealthCommand extends Command
{
    public function __construct(private readonly CompetitionHealthService $health)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the raw snapshot as JSON')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit 1 if any warning is reported');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $snapshot = $this->health->snapshot();

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $input->getOption('strict') && !$snapshot['healthy'] ? Command::FAILURE : Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Competition processing health');

        $io->definitionList(
            ['As of' => $snapshot['at']],
            new \Symfony\Component\Console\Helper\TableSeparator(),
            ['Queue (' . $snapshot['queue']['transport'] . ')' => $snapshot['queue']['unavailable'] ?? sprintf(
                '%d pending, oldest %s, %d failed',
                $snapshot['queue']['pending'],
                $this->age($snapshot['queue']['oldestPendingSeconds']),
                $snapshot['queue']['failed'],
            )],
            ['Round claims' => sprintf(
                '%d held now, %d past the lease',
                $snapshot['rounds']['claimed'],
                $snapshot['rounds']['staleClaims'],
            )],
            ['Overdue rounds' => sprintf(
                '%d draw, %d resolve, worst %s',
                $snapshot['rounds']['overdueDraw'],
                $snapshot['rounds']['overdueResolve'],
                $this->age($snapshot['rounds']['worstOverdueSeconds']),
            )],
            ['Response cache' => sprintf(
                '%d row(s) for %d open competition(s); %d open without one, %d on an old payload version, oldest built %s',
                $snapshot['cache']['rows'],
                $snapshot['cache']['openCompetitions'],
                $snapshot['cache']['openWithoutRow'],
                $snapshot['cache']['stalePayloadVersion'],
                $this->age($snapshot['cache']['oldestGeneratedSeconds']),
            )],
        );

        if ($snapshot['healthy']) {
            $io->success('Keeping up — nothing overdue, no stale claims.');

            return Command::SUCCESS;
        }

        foreach ($snapshot['warnings'] as $warning) {
            $io->warning($warning);
        }

        return $input->getOption('strict') ? Command::FAILURE : Command::SUCCESS;
    }

    private function age(?int $seconds): string
    {
        if ($seconds === null) {
            return 'n/a';
        }

        return $seconds < 120 ? sprintf('%ds ago', $seconds) : sprintf('%dm ago', intdiv($seconds, 60));
    }
}

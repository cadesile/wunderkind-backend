<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Scout;
use App\Service\ScoutWageCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prices every scout generated before `scout.weekly_salary` existed.
 *
 * The column lands DEFAULT 0, which is the only safe default for a migration (it cannot invent
 * a figure per row) but is also a lie: a free scout understates a club's running costs, which
 * is precisely the projection POST /api/club/fund reports. So the column needs this pass behind
 * it, and the migration says so.
 *
 * ACTS ONLY ON ZERO, so re-running is a no-op and an admin who deliberately set a scout's wage
 * to something is not overwritten. `--force` re-prices every row instead, for the one case the
 * default cannot cover: an operator who changed the PoolConfig wage range and wants the
 * existing world to follow it.
 *
 * ZERO IS INDISTINGUISHABLE FROM A DELIBERATE ZERO, and that is accepted rather than solved.
 * A genuinely free scout is not a state anything creates — generation always writes a figure
 * from the configured floor, which defaults to £25/week — so the sentinel only misfires if an
 * admin sets a wage to 0 on purpose and then runs this.
 */
#[AsCommand(
    name: 'app:backfill-scout-wages',
    description: 'Derive weekly_salary from experience for scouts that have none',
)]
final class BackfillScoutWagesCommand extends Command
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScoutWageCalculator    $wageCalculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change, write nothing.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Re-price every scout, not just the unpriced ones — use after changing the configured range.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');

        if ($dryRun) {
            $io->note('Dry run — no changes will be written.');
        }

        $qb = $this->em->getRepository(Scout::class)->createQueryBuilder('s');

        if (!$force) {
            $qb->where('s.weeklySalary = 0');
        }

        /** @var list<Scout> $scouts */
        $scouts = $qb->getQuery()->getResult();

        if ($scouts === []) {
            $io->success('Nothing to do — every scout already carries a wage.');

            return Command::SUCCESS;
        }

        $changed = 0;
        $n       = 0;

        foreach ($scouts as $scout) {
            $wage = $this->wageCalculator->weeklyWageForScout($scout);

            if ($wage === $scout->getWeeklySalary()) {
                continue;
            }

            ++$changed;

            if ($dryRun) {
                continue;
            }

            $scout->setWeeklySalary($wage);

            if (++$n % self::BATCH_SIZE === 0) {
                $this->em->flush();
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            $dryRun
                ? '%d of %d scout(s) would be repriced.'
                : '%d of %d scout(s) repriced.',
            $changed,
            count($scouts),
        ));

        return Command::SUCCESS;
    }
}

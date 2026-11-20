<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Scout;
use App\Service\ScoutAbilityCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-derives `scout.ability` from each scout's own `experience` + `judgements`.
 *
 * ⚠️ **THIS IS NOT THE INITIAL BACKFILL** — unlike `app:backfill-scout-wages`, whose column
 * landed `DEFAULT 0` and needed a pass behind it. `Version20261120100000` derives every existing
 * row inside the migration itself, because 0 is not a legal ability and A24 prices a wage off
 * this column. So on a database that has run that migration this command is a no-op, and that
 * is the expected result rather than a sign something went wrong.
 *
 * What it is actually for:
 *   - **re-derivation after the formula or the inputs change** (`--force`): an operator who
 *     retunes `ScoutAbilityCalculator`'s weighting, or edits a scout's experience in the admin,
 *     needs the stored figure to follow. The migration cannot be re-run and must not be edited.
 *   - **rows a later import leaves behind**: anything that writes scouts by raw SQL rather than
 *     through `ScoutGenerationService` lands the column's floor of 1.
 *
 * ACTS ONLY ON THE FLOOR (`ability = 1`) by default, so re-running is safe. `--force` re-derives
 * every row. There is no "deliberate 1" to protect, because the admin exposes `ability`
 * READ-ONLY on purpose — an editable derived field is a second source for one quantity, which is
 * the defect this workstream exists to remove. That is also why this command has no
 * single-scout override: the formula is the only writer.
 *
 * ⚠️ A genuine ability of 1 is reachable (experience 1, no judgements), so the default filter
 * cannot distinguish it from an underived row. It re-derives it to the same 1 and reports no
 * change, so the ambiguity is harmless — but it is the reason the summary counts CHANGED rows
 * rather than inspected ones.
 */
#[AsCommand(
    name: 'app:backfill-scout-abilities',
    description: 'Re-derive ability from experience + judgements for scouts that have none',
)]
final class BackfillScoutAbilitiesCommand extends Command
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScoutAbilityCalculator $abilityCalculator,
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
                'Re-derive every scout, not just the underived ones — use after retuning the formula.',
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
            $qb->where('s.ability = 1');
        }

        /** @var list<Scout> $scouts */
        $scouts = $qb->getQuery()->getResult();

        if ($scouts === []) {
            $io->success('Nothing to do — every scout already carries a derived ability.');

            return Command::SUCCESS;
        }

        $changed = 0;
        $n       = 0;

        foreach ($scouts as $scout) {
            $ability = $this->abilityCalculator->abilityForScout($scout);

            if ($ability === $scout->getAbility()) {
                continue;
            }

            ++$changed;

            if ($dryRun) {
                continue;
            }

            $scout->setAbility($ability);

            if (++$n % self::BATCH_SIZE === 0) {
                $this->em->flush();
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            $dryRun
                ? '%d of %d scout(s) would have ability re-derived.'
                : '%d of %d scout(s) had ability re-derived.',
            $changed,
            count($scouts),
        ));

        return Command::SUCCESS;
    }
}

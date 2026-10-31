<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Enum\LeaderboardCategory;
use App\Repository\Leaderboard\LeaderboardAwardDefinitionRepository;
use App\Service\Leaderboard\LeaderboardPeriodCloseService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * A thin SymfonyStyle wrapper over LeaderboardPeriodCloseService — the
 * GenerateClubSpotlightCommand precedent, including returning SUCCESS when nothing
 * qualified. "Nothing was due" is the normal outcome on all but a handful of ticks, and a
 * non-zero exit for it would page someone every ten minutes.
 *
 * There is no crontab under Lando, so local verification is:
 *
 *   lando php bin/console app:leaderboards:close-periods --force --dry-run -v
 *   lando php bin/console app:leaderboards:close-periods --force -v
 */
#[AsCommand(
    name: 'app:leaderboards:close-periods',
    description: 'Close any leaderboard award definition whose configured period has ended: archive the board, pay the places, prune the live rows',
)]
class CloseLeaderboardPeriodsCommand extends Command
{
    public function __construct(
        private readonly LeaderboardPeriodCloseService $closeService,
        private readonly LeaderboardAwardDefinitionRepository $definitions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('definition', 'd', InputOption::VALUE_OPTIONAL, 'Restrict to one definition, by uuid or by category slug')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would close. Claims nothing and writes nothing.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Close the CURRENT, still-open period early — for ops and local testing')
            ->addOption('at', null, InputOption::VALUE_OPTIONAL, 'Pin "now" to an ISO 8601 instant, for tests');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = $this->resolveNow($input, $io);
        if ($now === false) {
            return Command::FAILURE;
        }

        $only = $this->resolveDefinitions($input, $io);
        if ($only === false) {
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');

        if ($dryRun) {
            $io->note('Dry run — nothing is claimed and nothing is written.');
        }

        if ($force) {
            $io->warning('--force closes the CURRENT, still-open period. Intended for ops and local testing.');
        }

        $report = $this->closeService->closeDuePeriods($now, $only, $dryRun, $force);

        if ($report['closed'] === []) {
            $io->success(sprintf(
                'Nothing due. %d definition(s) examined.',
                count($report['skipped']),
            ));

            return Command::SUCCESS;
        }

        foreach ($report['closed'] as $outcome) {
            $io->section(sprintf('%s — %s', $outcome['definition'], $outcome['period']));
            $io->text('Result: ' . $outcome['result']);

            if (isset($outcome['eligible'])) {
                $io->text('Eligible clubs: ' . $outcome['eligible']);
            }

            if (isset($outcome['archived'])) {
                $io->text(sprintf('Archived rows: %d   Awards: %d   Pruned live rows: %d', $outcome['archived'], $outcome['awarded'], $outcome['pruned']));
            }

            if (($outcome['places'] ?? []) !== []) {
                $io->table(
                    ['Rank', 'Club', 'Score', 'Would award'],
                    array_map(
                        static fn (array $p): array => [$p['rank'], $p['club'], $p['score'], $p['wouldAward'] ? 'yes' : 'no'],
                        $outcome['places'],
                    ),
                );
            }
        }

        $io->success($dryRun
            ? sprintf('%d period(s) would close.', count($report['closed']))
            : sprintf('%d period(s) closed.', count($report['closed'])));

        return Command::SUCCESS;
    }

    /** @return \DateTimeImmutable|false */
    private function resolveNow(InputInterface $input, SymfonyStyle $io): \DateTimeImmutable|false
    {
        $at = $input->getOption('at');

        if ($at === null) {
            return new \DateTimeImmutable();
        }

        try {
            return new \DateTimeImmutable((string) $at);
        } catch (\Exception) {
            $io->error("Could not parse --at value '{$at}'. Use an ISO 8601 instant.");

            return false;
        }
    }

    /** @return LeaderboardAwardDefinition[]|null|false null = every enabled definition */
    private function resolveDefinitions(InputInterface $input, SymfonyStyle $io): array|null|false
    {
        $option = $input->getOption('definition');

        if ($option === null) {
            return null;
        }

        $option = (string) $option;

        // Guarded: handing a non-UUID string to find() on a uuid-keyed entity throws
        // "Invalid UUID" out of the DBAL type rather than returning null.
        if (Uuid::isValid($option)) {
            $byId = $this->definitions->find($option);

            if ($byId !== null) {
                return [$byId];
            }

            $io->error("No award definition with id '{$option}'.");

            return false;
        }

        $category = LeaderboardCategory::tryFrom($option);
        if ($category === null) {
            $io->error("'{$option}' is neither a definition id nor a leaderboard category slug.");

            return false;
        }

        $matches = $this->definitions->findBy(['category' => $category]);

        if ($matches === []) {
            $io->error("No award definition exists for category '{$option}'.");

            return false;
        }

        return $matches;
    }
}

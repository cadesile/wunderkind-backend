<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Location\Location;
use App\Enum\Country;
use App\Repository\Location\LocationRepository;
use App\Repository\Location\StadiumEstimateRepository;
use App\Service\Location\StadiumEstimateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds stadium estimates that are missing, expired, or priced off a land value that has
 * since changed.
 *
 * Costs nothing external — this is arithmetic over data already in the table, unlike
 * app:locations:enrich — so it is safe to run on a schedule and safe to run twice.
 *
 * Run it after a land revaluation and after bumping StadiumEstimateService::MODEL_VERSION. A
 * version bump does NOT invalidate outstanding holds: their quote points at the old estimate
 * row, which is superseded rather than deleted precisely so the promise "this price will not
 * move for fifteen minutes" survives a model change.
 */
#[AsCommand(
    name: 'app:plots:refresh-estimates',
    description: 'Recompute stadium build-price estimates that are missing, stale, or built from an outdated land value.',
)]
class RefreshStadiumEstimatesCommand extends Command
{
    public function __construct(
        private readonly StadiumEstimateService    $estimateService,
        private readonly StadiumEstimateRepository $estimateRepository,
        private readonly LocationRepository        $locationRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::OPTIONAL, 'Restrict to one country code, e.g. EN.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum estimates to rebuild.', '5000')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Rebuild every priceable location in scope, not only the stale ones.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be rebuilt and change nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $country = $input->getArgument('country');
        $country = $country === null ? null : strtoupper(trim((string) $country));
        $limit   = max(1, (int) $input->getOption('limit'));
        $all     = (bool) $input->getOption('all');
        $dryRun  = (bool) $input->getOption('dry-run');

        if ($country !== null && Country::tryFrom($country) === null) {
            $io->error("'{$country}' is not an App\\Enum\\Country case.");

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Model' => StadiumEstimateService::MODEL_VERSION],
            ['Scope' => $country ?? 'all countries'],
            ['Mode' => $all ? 'every priceable location' : 'stale or missing only'],
        );

        $locationIds = $all
            ? array_map(
                static fn (Location $l): string => (string) $l->getId(),
                array_filter(
                    $this->locationRepository->findByCountry((string) $country),
                    static fn (Location $l): bool => $l->isActive() && $l->getPopulation() !== null && $l->getLandPence() !== null,
                ),
            )
            : $this->estimateRepository->findLocationIdsNeedingEstimate(
                StadiumEstimateService::MODEL_VERSION,
                new \DateTimeImmutable(),
                $country,
                $limit,
            );

        if ($all && $country === null) {
            $io->error('--all needs a country. Use the default stale-only mode for a global pass.');

            return Command::FAILURE;
        }

        if ($locationIds === []) {
            $io->success('Nothing to rebuild — every priceable location in scope has a current estimate.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('<info>%d</info> location(s) need an estimate.', count($locationIds)));

        if ($dryRun) {
            $io->note('Dry run — nothing written.');

            return Command::SUCCESS;
        }

        $built   = 0;
        $skipped = 0;

        foreach (array_slice($locationIds, 0, $limit) as $locationId) {
            $location = $this->locationRepository->find($locationId);

            if ($location === null) {
                continue;
            }

            // Null means the location cannot be priced — no population or no land value — which
            // is a legitimate pre-enrichment state rather than a failure.
            if ($this->estimateService->refresh($location) === null) {
                ++$skipped;
                continue;
            }

            ++$built;
        }

        $io->table(
            ['Rebuilt', 'Skipped (not priceable)'],
            [[(string) $built, (string) $skipped]],
        );

        if ($skipped > 0) {
            $io->note('Skipped locations are missing a population or a land value — run app:locations:enrich.');
        }

        $io->success(sprintf('Rebuilt %d stadium estimate(s).', $built));

        return Command::SUCCESS;
    }
}

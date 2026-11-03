<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Location\LocationRival;
use App\Enum\Country;
use App\Repository\Location\LocationRepository;
use App\Service\Location\LocationRivalService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recomputes locations' nearest-rival sets from their coordinates.
 *
 * Run it AFTER enrichment, and expect to run it more than once: coordinates arrive one location
 * at a time, and each newly placed town changes its neighbours' nearest four as well as its
 * own. `--stale-only` (the default) is the cheap repeat pass that picks those up; enrichment
 * marks the affected radius stale for exactly this reason.
 *
 * A location with no coordinates is skipped, not failed — before enrichment reaches it, that is
 * every location. The command reports the skip count so "0 processed, 865 skipped" reads as
 * "nothing is enriched yet" rather than as a malfunction.
 */
#[AsCommand(
    name: 'app:locations:rivals',
    description: 'Recompute nearest-rival sets for locations from their coordinates.',
)]
class LocationRivalsCommand extends Command
{
    public function __construct(
        private readonly LocationRivalService $rivalService,
        private readonly LocationRepository   $locationRepository,
        private readonly Connection           $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::OPTIONAL, 'Restrict to one country code, e.g. EN.')
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Recompute every location in scope, not just those marked stale. Needs a country.',
            )
            ->addOption(
                'cross-border',
                null,
                InputOption::VALUE_NONE,
                'Allow rivals in neighbouring countries. Off by default — a league pyramid is national.',
            )
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum locations to process.', '5000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be recomputed and change nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $country     = $input->getArgument('country');
        $country     = $country === null ? null : strtoupper(trim((string) $country));
        $all         = (bool) $input->getOption('all');
        $crossBorder = (bool) $input->getOption('cross-border');
        $dryRun      = (bool) $input->getOption('dry-run');
        $limit       = max(1, (int) $input->getOption('limit'));

        if ($country !== null && Country::tryFrom($country) === null) {
            $io->error("'{$country}' is not an App\\Enum\\Country case.");

            return Command::FAILURE;
        }

        if ($all && $country === null) {
            $io->error('--all needs a country. Recomputing every location on earth is never what you want; use --stale-only globally instead.');

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Scope' => $country ?? 'all countries'],
            ['Mode' => $all ? 'every location' : 'stale only'],
            ['Radius' => sprintf('%.0f miles (%.1f km)', LocationRivalService::SEARCH_RADIUS_MILES, LocationRivalService::searchRadiusKm())],
            ['Rivals per location' => (string) LocationRival::MAX_RANK],
            ['Borders' => $crossBorder ? 'cross-border allowed' : 'same country only'],
        );

        $withCoords    = $this->countWithCoordinates($country);
        $withoutCoords = $this->countWithoutCoordinates($country);

        if ($withCoords === 0) {
            $io->warning(sprintf(
                'No location in scope has coordinates yet (%d awaiting enrichment). '
                . 'Run app:locations:enrich first — rival distances are computed from coordinates.',
                $withoutCoords,
            ));

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $candidates = $all
                ? $withCoords
                : count($this->locationRepository->findWithStaleRivals($country, $limit));

            $io->note(sprintf(
                'Dry run — would recompute %d location(s). %d have coordinates, %d do not.',
                $candidates,
                $withCoords,
                $withoutCoords,
            ));

            return Command::SUCCESS;
        }

        $stats = $all
            ? $this->rivalService->recomputeCountry($country, $crossBorder)
            : $this->rivalService->recomputeStale($country, $limit, $crossBorder);

        $io->table(
            ['Processed', 'Rivals stored', 'Skipped (no coordinates)'],
            [[(string) $stats['processed'], (string) $stats['rivals'], (string) $stats['skipped']]],
        );

        if ($stats['processed'] === 0) {
            $io->success('Nothing stale — every rival set in scope is up to date.');

            return Command::SUCCESS;
        }

        $isolated = $stats['processed'] - $this->countWithRivals($country);

        if ($isolated > 0) {
            // Worth saying out loud: zero rivals is a real answer for a remote town, not a
            // failure, and an operator should not go looking for a bug.
            $io->note(sprintf(
                '%d processed location(s) have no rival within %.0f miles. That is a valid result for an isolated town.',
                max(0, $isolated),
                LocationRivalService::SEARCH_RADIUS_MILES,
            ));
        }

        $io->success(sprintf('Recomputed %d location(s), storing %d rival link(s).', $stats['processed'], $stats['rivals']));

        return Command::SUCCESS;
    }

    private function countWithCoordinates(?string $country): int
    {
        return $this->countLocations($country, 'lat IS NOT NULL AND lng IS NOT NULL');
    }

    private function countWithoutCoordinates(?string $country): int
    {
        return $this->countLocations($country, 'lat IS NULL OR lng IS NULL');
    }

    private function countLocations(?string $country, string $predicate): int
    {
        $sql    = "SELECT COUNT(*) FROM location WHERE status = 'active' AND ({$predicate})";
        $params = [];

        if ($country !== null) {
            $sql .= ' AND country_code = :country';
            $params['country'] = $country;
        }

        return (int) $this->connection->fetchOne($sql, $params);
    }

    private function countWithRivals(?string $country): int
    {
        $sql    = 'SELECT COUNT(DISTINCT r.location_id) FROM location_rival r JOIN location l ON l.id = r.location_id';
        $params = [];

        if ($country !== null) {
            $sql .= ' WHERE l.country_code = :country';
            $params['country'] = $country;
        }

        return (int) $this->connection->fetchOne($sql, $params);
    }
}

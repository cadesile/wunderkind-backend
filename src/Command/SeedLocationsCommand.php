<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Country;
use App\Service\Location\CountryReadinessService;
use App\Service\Location\LocationSeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The CLI face of LocationSeedService.
 *
 * ALL THE SEEDING LOGIC LIVES IN THE SERVICE, not here — this class resolves arguments and
 * renders tables, nothing else. The admin "Seed" buttons call exactly the same service, so the
 * two faces cannot drift; that is the LocationEnrichmentOrchestrator arrangement, applied for
 * the same reason.
 *
 * Read that service's docblock for what is written, what is deliberately left NULL (lat, lng
 * and land_pence — no coordinate data exists in PHP to seed), and why this is act-only-when-
 * empty rather than an upsert.
 */
#[AsCommand(
    name: 'app:locations:seed',
    description: 'Seeds country_profile, location and land_plot rows from CountryContentRegistry. Per-country no-op once a country has locations.',
)]
class SeedLocationsCommand extends Command
{
    /**
     * @deprecated Moved to LocationSeedService, which is where the rows are actually written.
     *             Kept so an existing reference does not break silently.
     */
    public const POPULATION_SOURCE = LocationSeedService::POPULATION_SOURCE;

    public function __construct(
        private readonly LocationSeedService     $seedService,
        private readonly CountryReadinessService $readinessService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'country',
                InputArgument::OPTIONAL,
                'ISO 3166-1 alpha-2 code (e.g. EN, JP). Omit to seed every country the registry has places for.',
            )
            ->addOption(
                'force-country',
                null,
                InputOption::VALUE_REQUIRED,
                'Add any MISSING towns to a country that already has locations. Implies that country as the scope. Never rewrites an existing row.',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be written and change nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $forced   = $input->getOption('force-country');
        $forced   = is_string($forced) ? strtoupper(trim($forced)) : null;
        $argument = $input->getArgument('country');

        if ($argument !== null && $forced !== null && strtoupper(trim((string) $argument)) !== $forced) {
            $io->error('--force-country must name the same country as the argument, or the argument must be omitted.');

            return Command::FAILURE;
        }

        // --force-country implies its own scope. Without this, `--force-country=EN` with no
        // argument fell through to "seed every country", which is a surprising amount of
        // writing for a flag that names one country.
        $scope = $argument !== null ? (string) $argument : $forced;

        if ($scope !== null) {
            try {
                $countries = [$this->seedService->resolveCountry($scope)];
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        } else {
            $countries = $this->seedService->seedableCountries();
        }

        if ($countries === []) {
            $io->warning('No countries have place data in CountryContentRegistry.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note('Dry run — nothing will be written.');
        }

        $result = $this->seedService->seedMany($countries, $forced, $dryRun);

        foreach ($result['rows'] as $row) {
            if ($row['citySizes'] !== []) {
                $io->writeln(sprintf(
                    '  <info>%s</info> classified: %s',
                    $row['country'],
                    implode(', ', array_map(
                        static fn (string $size, int $n): string => "{$size}={$n}",
                        array_keys($row['citySizes']),
                        $row['citySizes'],
                    )),
                ));
            }
        }

        $io->table(
            ['Country', 'Locations added', 'Outcome'],
            array_map(static fn (array $r): array => [
                $r['country'],
                (string) $r['locations'],
                $r['outcome'],
            ], $result['rows']),
        );

        if ($dryRun) {
            $io->success(sprintf('Dry run: would add %d location(s).', $result['locations']));

            return Command::SUCCESS;
        }

        if ($result['locations'] > 0) {
            // Said loudly on purpose: a seeded country is NOT ready to go live, and the reason
            // is not obvious from the row count alone.
            $io->warning(sprintf(
                '%d location(s) have no coordinates and no land value — there is none in PHP to seed, '
                . 'so they have NO PLOTS either and cannot be bought yet. Run app:locations:enrich '
                . 'to value them (which provisions their plots), then app:locations:rivals.',
                $result['locations'],
            ));
        }

        $this->reportReadiness($countries, $io);

        $io->success(sprintf('Seeded %d location(s).', $result['locations']));

        return Command::SUCCESS;
    }

    /** @param list<Country> $countries */
    private function reportReadiness(array $countries, SymfonyStyle $io): void
    {
        $rows = [];

        foreach ($countries as $country) {
            $verdict = $this->readinessService->evaluate($country->value);

            $rows[] = [
                $country->value,
                $verdict['ready'] ? 'ready' : 'not ready',
                $verdict['blocking'] === [] ? '—' : implode(', ', $verdict['blocking']),
            ];
        }

        $io->section('Readiness (CountryReadinessService)');
        $io->table(['Country', 'Verdict', 'Blocking checks'], $rows);
        $io->writeln('A country is gated out of GET /api/locations until its profile status is LIVE.');
    }
}

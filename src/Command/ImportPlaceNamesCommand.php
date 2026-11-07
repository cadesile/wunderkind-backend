<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Location\LandPlotProvisioner;
use App\Service\Location\LocationSeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import the bare town names, so the catalogue is every town rather than only the curated ones.
 *
 * CountryContentRegistry holds two lists. app:locations:seed writes the 865 CURATED records,
 * which carry a population and a region. This writes the other ~4,200 — bare NAME STRINGS, with
 * nothing else. England has 42 curated and 280 bare, which is why Prestwich has always been a
 * name a player could build a club name from but never a place anyone could buy.
 *
 * They arrive unpriced and unplaced, status pending, and that is the design: enrichment fills
 * them in lazily, the first time somebody opens the town. Nothing sweeps them in bulk, so the
 * spend follows actual player interest instead of the size of the catalogue.
 *
 * SAFE TO RUN AFTER SEEDING AND SAFE TO RE-RUN. It adds only, matching on the normalised town
 * name, so an existing location is never touched.
 *
 * It does NOT enrich, and it does NOT provision plots. Run app:locations:provision-plots
 * afterwards — the command says so when it finishes.
 */
#[AsCommand(
    name: 'app:locations:import-names',
    description: 'Import the bare town names (~4,200) as unpriced locations for lazy enrichment.',
)]
class ImportPlaceNamesCommand extends Command
{
    public function __construct(
        private readonly LocationSeedService $seedService,
        private readonly LandPlotProvisioner $provisioner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('countries', InputArgument::IS_ARRAY, 'Country codes (default: all seedable)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be imported and write nothing')
            ->addOption(
                'with-plots',
                null,
                InputOption::VALUE_NONE,
                'Provision plots for the imported towns in the same pass',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $codes = $input->getArgument('countries');

        if ($codes === []) {
            $codes = array_map(
                static fn ($c): string => $c->value,
                $this->seedService->seedableCountries(),
            );
        }

        if ($dryRun) {
            $io->note('Dry run — nothing will be written.');
        }

        $rows           = [];
        $totalImported  = 0;
        $nearDuplicates = [];
        $errors         = [];

        foreach ($codes as $code) {
            try {
                $country = $this->seedService->resolveCountry((string) $code);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
                continue;
            }

            try {
                $result = $this->seedService->importPlaceNames($country, $dryRun);
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
                continue;
            }

            if ($result['imported'] === 0 && $result['nearDuplicates'] === []) {
                continue;
            }

            $rows[] = [
                $result['country'],
                $result['imported'],
                $result['skipped'],
                $result['duplicates'],
            ];

            $totalImported += $result['imported'];

            foreach ($result['nearDuplicates'] as $pair) {
                $nearDuplicates[] = [$result['country'], $pair['imported'], $pair['existing']];
            }
        }

        if ($rows !== []) {
            $io->table(['Country', 'Imported', 'Already present', 'Repeats in registry'], $rows);
        }

        if ($nearDuplicates !== []) {
            $io->section('Possible duplicates — imported anyway, for a human to judge');
            $io->table(['Country', 'Imported as', 'Looks like existing'], $nearDuplicates);
            $io->note(
                'These were NOT merged. Some are the same city written two ways ("Newcastle" vs '
                . '"Newcastle upon Tyne") and should be merged by hand; others are genuinely '
                . 'different places that merely read alike ("San Sebastián de los Reyes" is a '
                . 'Madrid suburb, not San Sebastián). Merging automatically would delete real towns.',
            );
        }

        foreach ($errors as $error) {
            $io->warning($error);
        }

        if ($dryRun) {
            $io->success(sprintf('Would import %d town(s).', $totalImported));

            return Command::SUCCESS;
        }

        if ($input->getOption('with-plots') && $totalImported > 0) {
            $added = 0;
            foreach ($codes as $code) {
                $added += $this->provisioner->provisionCountry((string) $code)['plotsAdded'];
            }

            // Usually zero, and that is correct: imported names carry no population and no land
            // value, and plots follow pricing. This tops up any town the country already holds
            // that was behind its size.
            $io->text(sprintf(
                'Provisioned %d plot(s). Freshly imported names get none until enrichment prices them.',
                $added,
            ));
        }

        $io->success(sprintf('Imported %d town(s).', $totalImported));

        $io->text([
            'Imported towns have no coordinates, no population and no land value — enrichment',
            'fills those in the first time a player opens one. Nothing sweeps them in bulk, so',
            'the spend follows real interest.',
            '',
            'They are excluded from city-size banding and from NPC club generation until they',
            'have a population, so this cannot re-band an already-generated world.',
        ]);

        if (!$input->getOption('with-plots')) {
            $io->note('Run app:locations:provision-plots to give the new towns their plots.');
        }

        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }
}

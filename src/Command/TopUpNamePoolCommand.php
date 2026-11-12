<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\Location\CountryProfileRepository;
use App\Service\CountryContent\NamePoolService;
use App\Service\CountryContent\NamePoolValidator;
use App\Service\CountryContent\Suggestion\NamePoolUnavailableException;
use App\Service\Location\LocationSeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The CLI twin of the dashboard's TOP UP WITH AI button.
 *
 * Every admin trigger in this area has one, so the two cannot drift — and this one earns it
 * twice over: --dry-run is how the prompt gets iterated on without spending, and the output
 * lists the names where a flash can only count them.
 */
#[AsCommand(
    name: 'app:names:top-up',
    description: 'Ask the AI provider for the first names and surnames a country is short of.',
)]
class TopUpNamePoolCommand extends Command
{
    public function __construct(
        private readonly CountryProfileRepository $countryProfileRepository,
        private readonly LocationSeedService $seedService,
        private readonly NamePoolService $namePools,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2 country code (e.g. AL)')
            ->addOption('list', null, InputOption::VALUE_REQUIRED, 'Only one list: firstNames or lastNames')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Names wanted per list', NamePoolValidator::TARGET_PER_LIST)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the shortfall and call nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $code = strtoupper(trim((string) $input->getArgument('country')));

        try {
            $country = $this->seedService->resolveAnyCountry($code);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Same as the admin path: a country with no profile row gets one, because name pools
        // hang off it and app:locations:seed cannot create a row for a country the registry
        // has no places for.
        $profile = $this->seedService->ensureCountryProfile($country);

        $target = max(1, (int) $input->getOption('target'));
        $only   = $input->getOption('list');
        $lists  = $only === null ? NamePoolService::LISTS : [(string) $only];

        foreach ($lists as $list) {
            if (!\in_array($list, NamePoolService::LISTS, true)) {
                $io->error(sprintf("'%s' is not a name list. Use firstNames or lastNames.", $list));

                return Command::FAILURE;
            }
        }

        $nationality = $this->namePools->nationalityFor($profile);
        $dryRun      = (bool) $input->getOption('dry-run');

        // CHECKED AFTER --dry-run IS KNOWN, not before. Reporting a shortfall calls nothing
        // and costs nothing, and it is the first thing you want to see on a machine where the
        // key is not set — this one included, since the keys live in deploy secrets.
        if (!$dryRun && !$this->namePools->suggesterIsConfigured()) {
            $io->error('No AI provider is configured. See docs/world-generation/adding-a-country.md.');

            return Command::FAILURE;
        }

        $io->section(sprintf('%s (%s)', $country->label(), $nationality));

        foreach ($lists as $list) {
            $held  = $this->namePools->effective($nationality, $list);
            $short = $target - \count($held);

            if ($short <= 0) {
                $io->text(sprintf('  %-11s %d held — already at target.', $list, \count($held)));

                continue;
            }

            if ($dryRun) {
                $io->text(sprintf('  %-11s %d held, would ask for %d.', $list, \count($held), $short));

                continue;
            }

            try {
                $result = $this->namePools->topUp($profile, $list, $target);
            } catch (NamePoolUnavailableException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            /** @var \App\Service\CountryContent\ValidatedNamePool $verdict */
            $verdict = $result['verdict'];

            if (isset($verdict->rejected[$list])) {
                $io->warning(sprintf('%s: %s', $list, $verdict->rejected[$list]));

                continue;
            }

            $io->text(sprintf('  %-11s %d held → +%d.', $list, $result['held'], $result['added']));

            foreach ($verdict->flatNotes() as $note) {
                $io->text('    ' . $note);
            }
        }

        // Read them over — these are culture, not fact, and the model is better at some
        // countries than others.
        if (!$dryRun) {
            foreach (NamePoolService::LISTS as $list) {
                $names = $this->namePools->effective($nationality, $list);
                $io->section(sprintf('%s (%d)', $list, \count($names)));
                $io->text(wordwrap(implode(', ', \array_slice($names, 0, 60)), 100));
            }
        }

        $io->success($dryRun ? 'Dry run — nothing was asked for and nothing was written.' : 'Done.');

        return Command::SUCCESS;
    }
}

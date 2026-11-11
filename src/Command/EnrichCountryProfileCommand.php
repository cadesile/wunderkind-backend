<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\Location\CountryProfileRepository;
use App\Service\Location\CountryEnrichment\CountryEnrichmentUnavailableException;
use App\Service\Location\CountryEnrichment\CountryProfileEnricherInterface;
use App\Service\Location\CountryEnrichment\CountryProfileEnrichmentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The CLI twin of the enrichment screen's PROFILE button.
 *
 * Every admin trigger in this area has one, so the two cannot drift — and this one earns its
 * keep twice over: --dry-run is how the prompt gets iterated on without writing anything, and
 * the output names every field and every refusal where a flash has to be terse.
 */
#[AsCommand(
    name: 'app:locations:enrich-country',
    description: "Ask the AI provider for a country's bounding box, land price band and reference data.",
)]
class EnrichCountryProfileCommand extends Command
{
    public function __construct(
        private readonly CountryProfileRepository $countryProfileRepository,
        private readonly CountryProfileEnrichmentService $service,
        private readonly CountryProfileEnricherInterface $enricher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::REQUIRED, 'ISO 3166-1 alpha-2 country code (e.g. PL)')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Replace values the country already has. Off by default, so a re-run never undoes a hand correction')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ask and validate, but write nothing — not the profile, not a run row');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $code = strtoupper(trim((string) $input->getArgument('country')));

        $profile = $this->countryProfileRepository->findByCode($code);

        if ($profile === null) {
            $io->error("'{$code}' has no country profile — run app:locations:seed {$code} first.");

            return Command::FAILURE;
        }

        if (!$this->enricher->isConfigured()) {
            // Said before the call rather than after it, so an unconfigured setup costs
            // nothing to discover. The same reason the admin screen checks the connection
            // before a sweep.
            $io->error('No AI enricher is configured. See docs/world-generation/location-enrichment.md.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $verdict = $this->service->enrich(
                $profile,
                (bool) $input->getOption('overwrite'),
                null,
                $dryRun,
            );
        } catch (CountryEnrichmentUnavailableException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->section($dryRun ? "Would write for {$code}" : "Written for {$code}");

        if ($verdict->accepted === []) {
            $io->text('Nothing.');
        } else {
            foreach ($verdict->accepted as $field => $value) {
                $io->text(sprintf('  %-22s %s', $field, $this->render($value)));
            }
        }

        if ($verdict->skipped !== []) {
            $io->section('Left alone (already set — pass --overwrite to replace)');
            $io->listing($verdict->skipped);
        }

        if ($verdict->rejected !== []) {
            $io->section('Refused');
            foreach ($verdict->rejected as $field => $why) {
                $io->text(sprintf('  %-22s %s', $field, $why));
            }
        }

        // A refusal is not a command failure: the country is unchanged, the reasons are
        // printed, and the operator decides. Exiting non-zero would make a partially useful
        // answer look like a broken call in a script.
        $io->success($dryRun ? 'Dry run — nothing was written.' : 'Done.');

        return Command::SUCCESS;
    }

    private function render(mixed $value): string
    {
        if (\is_array($value)) {
            return (string) json_encode($value);
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}

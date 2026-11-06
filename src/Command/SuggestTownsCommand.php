<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Location\LocationSeedService;
use App\Service\Location\Suggestion\TownSuggestionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The CLI face of TownSuggestionService.
 *
 * ALL THE LOGIC LIVES IN THE SERVICE, not here — this resolves arguments and renders a
 * table, nothing else. The admin "Pull towns" button calls the same service, so the two
 * faces cannot drift; the same arrangement SeedLocationsCommand uses, for the same reason.
 *
 * THIS COSTS MONEY. One API call per run, which is cheap — but every town it adds becomes a
 * row that will cost one enrichment call later, when a player opens it. The connection check
 * runs first for the same reason the enrich command does it: discovering a mistyped key after
 * the fact is an incident this codebase has already had once.
 */
#[AsCommand(
    name: 'app:locations:suggest-towns',
    description: 'Asks the configured AI provider for more town names for a country, and adds the ones it does not already hold.',
)]
class SuggestTownsCommand extends Command
{
    public function __construct(
        private readonly TownSuggestionService $suggestionService,
        private readonly LocationSeedService $seedService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::REQUIRED, 'ISO country code, e.g. AR')
            ->addOption(
                'max',
                null,
                InputOption::VALUE_REQUIRED,
                'Most towns to add in one run. The reply is ordered largest-first, so a cap drops the smallest.',
                (string) TownSuggestionService::DEFAULT_MAX,
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Make the call and show what would be added, writing nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $max    = (int) $input->getOption('max');

        try {
            $country = $this->seedService->resolveCountry((string) $input->getArgument('country'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Before spending anything, as EnrichLocationsCommand does.
        $connection = $this->suggestionService->checkConnection();

        if (!$connection['ok']) {
            $io->error(\sprintf('No usable AI provider: %s', $connection['detail']));

            return Command::FAILURE;
        }

        $io->text($connection['detail']);

        try {
            $result = $this->suggestionService->suggestForCountry($country, $max, $dryRun);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Country' => $result['country']],
            ['Asked for' => $result['asked']],
            ['Offered' => $result['offered']],
            ['Accepted' => $result['accepted']],
            [$dryRun ? 'Would import' : 'Imported' => $result['imported']],
            ['Already held' => $result['alreadyHeld']],
            ['Rejected' => \count($result['rejected'])],
            ['Tokens' => \sprintf('%s in / %s out', $result['inputTokens'] ?? '?', $result['outputTokens'] ?? '?')],
        );

        foreach ($result['rejected'] as $name => $reason) {
            $io->warning(\sprintf('Rejected "%s": %s', $name, $reason));
        }

        if ($result['nearDuplicates'] !== []) {
            $io->section('Near-duplicates — added anyway, never merged');
            $io->table(['Added', 'Looks like'], array_map(
                static fn (array $d): array => [$d['imported'], $d['existing']],
                $result['nearDuplicates'],
            ));
        }

        if ($dryRun) {
            $io->note('Dry run — nothing was written.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d town(s) added to %s.', $result['imported'], $result['country']));

        // The towns are unpriced and unplaced until enrichment reaches them, which is lazy by
        // design. Said explicitly because the seed command says the same thing, and an
        // operator who reads "added" as "ready" will wonder why the country will not go live.
        $io->text('They have no coordinates and no land value yet; enrichment fills those in on first view.');

        return Command::SUCCESS;
    }
}

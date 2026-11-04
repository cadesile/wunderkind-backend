<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Location\NpcClubLocationLinker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Link existing NPC clubs to the town they were named after, and re-sync the town's facts onto
 * them.
 *
 * NPC clubs predate the location table, so the only record of which town one belongs to is its
 * own name. See NpcClubLocationLinker for the matching rule and the three hazards that make the
 * obvious alternatives wrong.
 *
 * ACT-ONLY-ON-UNLINKED by default, so running it twice is a no-op and it is safe on a cron or
 * after a deploy. `--relink` re-evaluates every club, which is what you want after seeding a new
 * country or after enrichment has moved a town's population.
 *
 * IT REWRITES LIVE DATA: region, city_size, population_size and is_capital are re-synced from the
 * linked town, so a club whose copied band had drifted will change band. Always --dry-run first.
 *
 * IT DOES NOT TOUCH THE WORLD-PACK CACHE. Cached packs are per country and tier with no TTL, and
 * nothing invalidates them on an NPC change — so clients keep receiving the OLD region/citySize
 * until that country is rebuilt. That is a deliberate choice, not an oversight; the command says
 * so at the end and names the fix.
 */
#[AsCommand(
    name: 'app:npc-clubs:link-locations',
    description: 'Link NPC clubs to their town and re-sync region, city size and population from it.',
)]
class LinkNpcClubLocationsCommand extends Command
{
    public function __construct(private readonly NpcClubLocationLinker $linker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'countries',
                InputArgument::IS_ARRAY,
                'Country codes to link (default: all)',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing')
            ->addOption(
                'relink',
                null,
                InputOption::VALUE_NONE,
                'Re-evaluate clubs that already have a location, instead of only unlinked ones',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $dryRun    = (bool) $input->getOption('dry-run');
        $relink    = (bool) $input->getOption('relink');
        $countries = array_map(
            static fn (string $c): string => strtoupper(trim($c)),
            $input->getArgument('countries'),
        );

        if ($dryRun) {
            $io->note('Dry run — nothing will be written.');
        }

        $result = $this->linker->link(
            countryCodes: $countries === [] ? null : $countries,
            relink: $relink,
            dryRun: $dryRun,
        );

        if ($result['considered'] === 0) {
            $io->success($relink
                ? 'No NPC clubs matched that scope.'
                : 'Every NPC club in scope is already linked. Use --relink to re-evaluate them.');

            return Command::SUCCESS;
        }

        if ($result['drift'] !== []) {
            $io->section('Clubs whose copied values disagree with their town');
            $io->table(
                ['Club', 'Town', 'Club size', 'Town size'],
                array_map(
                    static fn (array $r): array => [$r['club'], $r['town'], $r['clubSize'], $r['townSize']],
                    $result['drift'],
                ),
            );
        }

        if ($result['unmatchedSamples'] !== []) {
            $io->section('Clubs with no matching town');
            $io->table(
                ['Country', 'Club'],
                array_map(static fn (array $r): array => [$r['country'], $r['club']], $result['unmatchedSamples']),
            );
        }

        $io->definitionList(
            ['Considered' => $result['considered']],
            [$dryRun ? 'Would link' : 'Linked' => $result['linked']],
            [$dryRun ? 'Would re-sync' : 'Re-synced' => $result['resynced']],
            ['Unmatched' => $result['unmatched']],
        );

        if ($result['unmatched'] > 0) {
            // Expected rather than alarming: a country generated before its locations were
            // seeded drew its names from PLACEHOLDER_PLACES, and those towns do not exist.
            $io->note(sprintf(
                '%d club(s) have no town and were left unlinked. That is normal for countries '
                . 'generated before their locations were seeded (names like "Capital FC"), and for '
                . 'clubs imported or renamed by hand.',
                $result['unmatched'],
            ));
        }

        if ($dryRun) {
            $io->note('Dry run — nothing was written.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Linked %d NPC club(s).', $result['linked']));

        if ($result['resynced'] > 0) {
            $io->warning(sprintf(
                "%d club(s) had their region, city size or population rewritten from their town.\n"
                . 'Generated world packs are cached per country and tier with no TTL and are NOT '
                . 'invalidated by this — clients keep receiving the old values until that country '
                . 'is rebuilt. Run app:worldpack:warm <CODE> (or regenerate in admin) if you want '
                . 'them to catch up.',
                $result['resynced'],
            ));
        }

        return Command::SUCCESS;
    }
}

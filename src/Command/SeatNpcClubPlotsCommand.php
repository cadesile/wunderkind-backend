<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Country;
use App\Service\Location\NpcClubPlotSeatingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill: put existing NPC clubs on land plots.
 *
 * WHY A COMMAND AND NOT A MIGRATION. Seating depends on which plots are free, which depends on
 * live claims and holds — facts a migration running at deploy time would be reading in the
 * middle of play. It is also not a one-off: a town imported as a bare name has no plots until
 * enrichment prices it, so its clubs stay unseated until this runs again afterwards. Safe and
 * idempotent, so a nightly cron is a reasonable home for it.
 *
 * NEW CLUBS DO NOT NEED IT. NpcClubGenerationService seats at the end of every generation.
 */
#[AsCommand(
    name: 'app:npc-clubs:seat-plots',
    description: 'Seat NPC clubs on land plots, so an established club occupies a specific plot rather than a count.',
)]
class SeatNpcClubPlotsCommand extends Command
{
    public function __construct(private readonly NpcClubPlotSeatingService $seating)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'country',
            InputArgument::OPTIONAL,
            'ISO 3166-1 alpha-2 code. Omit to sweep every country that has an unseated club.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $arg = $input->getArgument('country');

        if ($arg !== null) {
            $code = strtoupper(trim((string) $arg));

            if (Country::tryFrom($code) === null) {
                $io->error("Unknown country code '{$code}'.");

                return Command::FAILURE;
            }

            $codes = [$code];
        } else {
            // Only countries with something to do, so a sweep over 71 countries is one query
            // plus however few actually need work.
            $codes = $this->seating->countriesNeedingSeats();
        }

        if ($codes === []) {
            $io->success('Every NPC club that can be seated already is.');

            return Command::SUCCESS;
        }

        $rows  = [];
        $total = 0;

        foreach ($codes as $code) {
            // Released first: a plot that was RETIRED rather than deleted keeps its row, so the
            // FK's ON DELETE SET NULL never fires and a club would still point at ground that
            // has left the town. Freeing those seats before seating lets the club move to a
            // plot that is actually active.
            $released = $this->seating->releaseRetiredSeats($code);
            $result   = $this->seating->seatCountry($code);

            $total += $result['seated'];
            $rows[] = [$code, $result['seated'], $released, $result['towns']];
        }

        $io->table(['Country', 'Seated', 'Released', 'Towns with a seated club'], $rows);

        $io->success(sprintf('%d club(s) seated across %d country(s).', $total, count($codes)));

        return Command::SUCCESS;
    }
}

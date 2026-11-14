<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Club;
use App\Entity\Club\ClubSale;
use App\Entity\User;
use App\Message\Club\SellClubMessage;
use App\Repository\Club\ClubSaleRepository;
use App\Repository\ClubRepository;
use App\Service\Club\ClubSaleValuationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queue club buyouts in bulk — the go-live batch.
 *
 * IT QUEUES, IT DOES NOT SELL. One `club_sale` row and one message per club; the work happens in
 * SellClubMessageHandler off the `clubsale` transport, the same handler the single-club admin
 * action uses. That is the whole reason the admin path was built queue-first: this command adds a
 * selection step and nothing else, so the batch cannot drift from the thing already tested
 * against individual accounts on dev. Same split as CompetitionResolveRoundsCommand, where the
 * cron tick queues and never resolves inline.
 *
 * IT PAYS THE ESTIMATE, UNREVIEWED, and that is the difference that matters between this and the
 * overlay. A human adjusting a figure does not scale to every account at go-live, so the model's
 * own output is authorised as-is. Two consequences follow, and both are why --dry-run exists and
 * why the default --limit is small:
 *
 *   - A club whose land was INFERRED from its name, or not found at all, is paid on that basis
 *     with nobody having looked. The report column says which, and --require-plot refuses those
 *     clubs outright for an operator who would rather handle them by hand.
 *   - A defect in the valuation becomes a defect in every payout in the run. The consumer drains
 *     a few per minute (docker/clubsale-consume.sh) precisely so a fault found early is a
 *     handful of clubs rather than the whole population.
 *
 * SPOOF CLUBS ARE EXCLUDED and cannot be included. They are generated test clones, so paying
 * their owners real account credit is never the intent — and the accounts behind them are
 * purged wholesale by app:clubs:purge-spoof, which would be crediting money into something about
 * to be deleted.
 *
 * SAFE TO RE-RUN. A club with a live sale (PENDING or COMPLETED) is skipped, so a second pass
 * after a partial run picks up only what is left. The partial unique index is the backstop and
 * the pre-check is what keeps a re-run quiet instead of erroring.
 */
#[AsCommand(
    name: 'app:clubs:sell-batch',
    description: 'Queue club buyouts in bulk, crediting each owner and scrubbing the club.',
)]
class SellClubsBatchCommand extends Command
{
    public function __construct(
        private readonly ClubRepository           $clubRepository,
        private readonly ClubSaleRepository       $saleRepository,
        private readonly ClubSaleValuationService $valuation,
        private readonly MessageBusInterface      $bus,
        private readonly EntityManagerInterface   $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('club', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Club id to sell. Repeatable.')
            ->addOption('user', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sell every club owned by this email. Repeatable.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Sell every eligible club. Respects --limit.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum clubs to queue in this run', '25')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Ledger note written on every sale. PLAYER-VISIBLE.')
            ->addOption(
                'require-plot',
                null,
                InputOption::VALUE_NONE,
                'Skip clubs whose land was inferred from the name or not found at all.',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be queued and change nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $needPlot = (bool) $input->getOption('require-plot');
        $limit    = max(1, (int) $input->getOption('limit'));
        $note     = $input->getOption('note');

        $clubs = $this->select($input, $io);

        if ($clubs === null) {
            return Command::INVALID;
        }

        if ($clubs === []) {
            $io->success('No eligible clubs.');

            return Command::SUCCESS;
        }

        $table   = [];
        $queued  = 0;
        $skipped = 0;
        $total   = 0;

        foreach ($clubs as $club) {
            if ($queued >= $limit) {
                break;
            }

            $clubId = (string) $club->getId();

            // Pre-checked rather than left to the unique index: a flush violation closes the
            // shared EntityManager and would abort the whole run on its first repeat.
            if ($this->saleRepository->findLiveForClub($clubId) !== null) {
                $skipped++;
                $table[] = [$club->getName(), $club->getUser()->getUserIdentifier(), '—', 'skipped: already sold or queued'];
                continue;
            }

            $breakdown = $this->valuation->breakdown($club);
            $basis     = (string) $breakdown['land']['basis'];

            if ($needPlot && !in_array($basis, ['active_claim', 'home_location'], true)) {
                $skipped++;
                $table[] = [$club->getName(), $club->getUser()->getUserIdentifier(), $this->pounds((int) $breakdown['estimatePence']), 'skipped: land ' . $basis];
                continue;
            }

            $estimate = (int) $breakdown['estimatePence'];
            $total   += $estimate;
            $queued++;

            if ($dryRun) {
                $table[] = [$club->getName(), $club->getUser()->getUserIdentifier(), $this->pounds($estimate), 'would queue (land ' . $basis . ')'];
                continue;
            }

            $sale = new ClubSale(
                $club->getUser(),
                $clubId,
                $club->getName(),
                $estimate,
                // The estimate IS the authorised figure on this path — nobody reviewed it.
                $estimate,
                $breakdown,
                $note !== null ? (string) $note : null,
                null,
                // No Admin principal on a console run. Recorded as the command rather than left
                // null, so the audit row says what authorised it.
                'console:app:clubs:sell-batch',
            );

            $this->em->persist($sale);
            $this->em->flush();

            // After the flush, always: the row is the work order and the message is only an id,
            // so dispatching first could deliver a message for a row that does not exist yet.
            $this->bus->dispatch(new SellClubMessage((string) $sale->getId()));

            $table[] = [$club->getName(), $club->getUser()->getUserIdentifier(), $this->pounds($estimate), 'queued (land ' . $basis . ')'];
        }

        $io->table(['Club', 'Owner', 'Estimate', 'Outcome'], $table);

        $io->definitionList(
            ['Considered' => count($clubs)],
            [$dryRun ? 'Would queue' : 'Queued' => $queued],
            [$dryRun ? 'Would credit' : 'To credit' => $this->pounds($total)],
            ['Skipped' => $skipped],
        );

        if ($dryRun) {
            $io->note('Dry run — nothing was queued and nobody will be paid.');

            return Command::SUCCESS;
        }

        if ($queued > 0) {
            $io->warning(sprintf(
                '%d buyout(s) are queued on the `clubsale` transport. Each one credits its owner and '
                . 'then PERMANENTLY deletes the club. Drain with: messenger:consume clubsale',
                $queued,
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Resolve the selection options into clubs. Null means the options were unusable.
     *
     * Requiring an explicit selector — and never defaulting to "everything" — is the one
     * safeguard a console command has that the admin overlay does not need: there is no
     * confirmation step here, so a bare invocation must do nothing rather than sell the world.
     *
     * @return list<Club>|null
     */
    private function select(InputInterface $input, SymfonyStyle $io): ?array
    {
        /** @var list<string> $clubIds */
        $clubIds = (array) $input->getOption('club');
        /** @var list<string> $emails */
        $emails = (array) $input->getOption('user');
        $all    = (bool) $input->getOption('all');

        if ($clubIds === [] && $emails === [] && !$all) {
            $io->error('Nothing selected. Pass --club, --user or --all.');

            return null;
        }

        $clubs = [];

        foreach ($clubIds as $id) {
            $club = $this->clubRepository->find($id);

            if ($club === null) {
                $io->warning(sprintf('No club with id %s — ignored.', $id));
                continue;
            }

            $clubs[$id] = $club;
        }

        foreach ($emails as $email) {
            // No UserRepository exists in this codebase; the entity manager's own repository is
            // the established way to reach User by email.
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

            if ($user === null) {
                $io->warning(sprintf('No account for %s — ignored.', $email));
                continue;
            }

            foreach ($user->getClubs() as $club) {
                $clubs[(string) $club->getId()] = $club;
            }
        }

        if ($all) {
            foreach ($this->clubRepository->findAll() as $club) {
                $clubs[(string) $club->getId()] = $club;
            }
        }

        // Spoof clubs are refused even when named explicitly by --club. See the class docblock:
        // they are generated clones whose owners are purged wholesale, so a credit into one is
        // never the intent, and an id typed by hand is no more an authorisation than a sweep.
        $eligible = [];

        foreach ($clubs as $club) {
            if ($club->isSpoof()) {
                continue;
            }

            $eligible[] = $club;
        }

        return $eligible;
    }

    private function pounds(int $pence): string
    {
        return '£' . number_format($pence / 100, 2);
    }
}

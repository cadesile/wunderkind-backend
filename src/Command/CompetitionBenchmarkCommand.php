<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionDrawService;
use App\Service\Competition\CompetitionResponseBuilder;
use App\Service\Competition\CompetitionResultsService;
use App\Service\Competition\CompetitionRegistrationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:competition:benchmark',
    description: 'Times the competition pipeline end to end, so the tuning numbers come from measurement rather than reasoning.',
)]
class CompetitionBenchmarkCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly CompetitionRegistrationService $registrationService,
        private readonly CompetitionDrawService $drawService,
        private readonly CompetitionResultsService $resultsService,
        private readonly CompetitionResponseBuilder $responseBuilder,
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('competitions', null, InputOption::VALUE_REQUIRED, 'How many competitions to run concurrently.', '3')
            ->addOption('capacity', null, InputOption::VALUE_REQUIRED, 'Entrant capacity each (4, 8, 16, 32 or 64).', '16')
            ->addOption('no-warmup', null, InputOption::VALUE_NONE, 'Skip the discarded warm-up pass. Only useful for seeing how much cold-start costs.')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Leave the generated competitions and spoof clubs behind for inspection.')
            ->setHelp(<<<'TXT'
                Phase 4 of docs/superpowers/plans/2026-10-28-tournament-concurrency-and-queues.md.

                Everything in that plan rests on reading the code and on tests that *simulate*
                contention. Three settings in particular are guesses:

                  * CompetitionRound::CLAIM_LEASE_SECONDS (15 min) — chosen to clear observed
                    per-round work, which had never been timed.
                  * competition-consume.sh's --limit=10 and --time-limit=50.

                This command produces the numbers those should be set from. It drives the real
                services — registration (including the capacity-fill lock), draw, resolve and
                the bracket document build — and reports wall-clock and peak memory per stage.

                It CREATES real rows (templates, spoof clubs and users, entrants, fixtures,
                results) and deletes them again unless --keep. It refuses to run with
                APP_ENV=prod.
                TXT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->appEnv === 'prod') {
            $io->error('Refusing to run in prod: this command creates and deletes real competition rows.');

            return Command::FAILURE;
        }

        $count    = max(1, (int) $input->getOption('competitions'));
        $capacity = (int) $input->getOption('capacity');

        if (!in_array($capacity, CompetitionTemplate::ALLOWED_ENTRANT_CAPACITIES, true)) {
            $io->error(sprintf('capacity must be one of: %s', implode(', ', CompetitionTemplate::ALLOWED_ENTRANT_CAPACITIES)));

            return Command::FAILURE;
        }

        $io->title(sprintf('Competition pipeline benchmark — %d x capacity %d', $count, $capacity));
        $io->writeln(sprintf('<comment>env=%s</comment>', $this->appEnv));

        // A discarded warm-up pass, and it is not optional window dressing. Without it the
        // first draw measured 526ms against 59ms for the next one on identical work — lazy
        // service instantiation, Doctrine metadata and query-plan caches, opcache. The
        // "slowest round" figure drives the lease recommendation below, so letting cold-start
        // dominate it would have produced a confidently wrong number.
        if (!$input->getOption('no-warmup')) {
            $io->writeln('<comment>warming up (one discarded capacity-4 pass)…</comment>');
            $this->warmUp();
        }

        /** @var list<ActiveCompetition> $competitions */
        $competitions  = [];
        $fillRows      = [];
        $regSamples    = [];
        $lockSamples   = [];
        $clubSetupMs   = 0.0;

        // ---- Stage 1: registration, with the capacity-fill lock isolated ------------------
        //
        // Club creation is timed separately and excluded from the registration figure. The
        // obvious way to fill a bracket here is spoofAllEntrants(), but that mints a User and
        // a Club per entrant — name generation, appearance generation — before registering
        // it, so its wall-clock reads as ~600ms "per registration" when almost none of that
        // is register(). Conflating the two would have produced a confidently wrong number
        // about the one path real players actually wait on.
        for ($i = 1; $i <= $count; $i++) {
            $template = new CompetitionTemplate(
                sprintf('Benchmark %d', $i),
                sprintf('benchmark-%d-%s', $i, uniqid('', true)),
                $capacity,
                CompetitionDuration::TEN_HOURS,
            );
            $this->em->persist($template);
            $instance = new ActiveCompetition($template);
            $this->em->persist($instance);
            $this->em->flush();

            $setup = $this->measure(fn () => $this->makeClubs($capacity, $i));
            $clubSetupMs += $setup['ms'];
            /** @var list<\App\Entity\Club> $clubs */
            $clubs = $setup['value'];

            $theseRegs = [];
            foreach ($clubs as $n => $club) {
                $envelope = $this->envelope($club, sprintf('Bench %d-%d', $i, $n + 1));
                $r = $this->measure(fn () => $this->registrationService->register($instance, $club, $envelope));

                // The final registration is the one that also locks the bracket, so it is
                // kept out of the per-registration distribution and reported on its own.
                if ($n === count($clubs) - 1) {
                    $lockSamples[] = $r['ms'];
                } else {
                    $regSamples[] = $r['ms'];
                    $theseRegs[]  = $r['ms'];
                }
            }

            $this->em->refresh($instance);
            $competitions[] = $instance;
            $fillRows[]     = [
                $i,
                $capacity,
                $this->ms($theseRegs !== [] ? array_sum($theseRegs) / count($theseRegs) : 0.0),
                $this->ms(end($lockSamples) ?: 0.0),
                $instance->getStatus()->value,
            ];
        }

        $io->section('Stage 1 — registration (club creation excluded; final registration isolated)');
        $io->table(
            ['#', 'entrants', 'avg registration ms', 'final (also locks) ms', 'status after'],
            $fillRows,
        );
        $io->writeln(sprintf(
            '<comment>club+user fixture creation, excluded above: %s ms total (%s ms each) — generation cost, not product path</comment>',
            $this->ms($clubSetupMs),
            $this->ms($clubSetupMs / max(1, $count * $capacity)),
        ));
        if ($regSamples !== []) {
            sort($regSamples);
            $io->writeln(sprintf(
                '<info>registration: median %s ms, worst %s ms over %d samples</info>',
                $this->ms($regSamples[(int) floor(count($regSamples) / 2)]),
                $this->ms(max($regSamples)),
                count($regSamples),
            ));
        }
        if ($lockSamples !== []) {
            $io->writeln(sprintf(
                '<info>capacity-fill lock: worst %s ms — the cost the final registrant pays for seeding, rounds and %d inbox messages</info>',
                $this->ms(max($lockSamples)),
                $capacity,
            ));
        }

        // ---- Stage 2: roll every bracket through every round ------------------------------
        $drawSamples    = [];
        $resolveSamples = [];
        $roundRows      = [];
        $future         = new \DateTimeImmutable('+10 years');

        foreach ($competitions as $idx => $instance) {
            foreach ($this->roundRepository->findByCompetitionOrderedByIndex($instance) as $round) {
                if ($round->getStatus() === CompetitionRoundStatus::DRAW_PENDING) {
                    $d = $this->measure(fn () => $this->drawService->drawRoundIfClaimable($round, $future));
                    $drawSamples[] = $d['ms'];
                } else {
                    $d = ['ms' => 0.0, 'peak' => 0];
                }

                $fixtures = count($this->roundRepository->findByCompetitionOrderedByIndex($instance)) > 0
                    ? $this->fixtureCount($round)
                    : 0;

                $r = $this->measure(fn () => $this->resultsService->publishResultsIfClaimable($round, $future));
                $resolveSamples[] = $r['ms'];

                $roundRows[] = [
                    $idx + 1,
                    $round->getRoundIndex(),
                    $round->getLabel(),
                    $fixtures,
                    $this->ms($d['ms']),
                    $this->ms($r['ms']),
                    $this->mb(max($d['peak'], $r['peak'])),
                ];
            }
        }

        $io->section('Stage 2 — draw and resolve, every round of every bracket');
        $io->table(['comp', 'round', 'label', 'fixtures', 'draw ms', 'resolve ms', 'peak MB'], $roundRows);

        // ---- Stage 3: the bracket document ------------------------------------------------
        $docRows = [];
        foreach ($competitions as $idx => $instance) {
            $built = $this->measure(fn () => $this->responseBuilder->sharedDocument($instance));
            $fp    = $this->measure(fn () => $this->responseBuilder->stateFingerprint($instance));
            $bytes = strlen((string) json_encode($built['value']));

            $docRows[] = [
                $idx + 1,
                $this->entrantRepository->countForCompetition($instance),
                $this->ms($built['ms']),
                $this->ms($fp['ms']),
                number_format($bytes / 1024, 1) . ' KB',
                $this->mb($built['peak']),
            ];
        }

        $io->section('Stage 3 — bracket response document (what GET /api/competitions/{id} serves)');
        $io->table(['comp', 'entrants', 'build ms', 'fingerprint ms', 'payload', 'peak MB'], $docRows);

        // Attribute the payload, because "the response is large" is not actionable but "this
        // key is most of it" is. narrativePayload is embedded per fixture even though
        // GET /api/matches/{id} already serves it per match, so this says what dropping it
        // from the bracket would actually buy.
        $last = end($competitions);
        if ($last !== false) {
            $doc   = $this->responseBuilder->sharedDocument($last);
            $total = strlen((string) json_encode($doc));

            $narrative = 0;
            $lineups   = 0;
            foreach ($doc['rounds'] ?? [] as $round) {
                foreach ($round['fixtures'] ?? [] as $fixture) {
                    $result = $fixture['result'] ?? null;
                    if (!is_array($result)) {
                        continue;
                    }
                    $narrative += strlen((string) json_encode($result['narrativePayload'] ?? null));
                    $lineups   += strlen((string) json_encode($result['homeLineup'] ?? null))
                        + strlen((string) json_encode($result['awayLineup'] ?? null));
                }
            }
            $entrants = strlen((string) json_encode($doc['entrants'] ?? []));

            $io->table(
                ['payload component', 'bytes', 'share'],
                [
                    ['result.narrativePayload (all fixtures)', number_format($narrative), $this->pct($narrative, $total)],
                    ['result lineups (all fixtures)', number_format($lineups), $this->pct($lineups, $total)],
                    ['entrants[]', number_format($entrants), $this->pct($entrants, $total)],
                    ['everything else', number_format(max(0, $total - $narrative - $lineups - $entrants)), $this->pct(max(0, $total - $narrative - $lineups - $entrants), $total)],
                    ['TOTAL', number_format($total), '100%'],
                ],
            );
        }

        // ---- What the numbers imply -------------------------------------------------------
        $this->reportImplications($io, $drawSamples, $resolveSamples);

        if ($input->getOption('keep')) {
            $io->warning('--keep: generated competitions and spoof clubs left in place.');

            return Command::SUCCESS;
        }

        $this->cleanUp($competitions);
        $io->success('Generated data removed.');

        return Command::SUCCESS;
    }


    /**
     * Runs one small competition end to end and throws it away, so the measured samples are
     * not polluted by first-call costs.
     */
    private function warmUp(): void
    {
        $template = new CompetitionTemplate('Benchmark warmup', 'benchmark-warmup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);
        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);
        $this->em->flush();

        foreach ($this->makeClubs(4, 0) as $n => $club) {
            $this->registrationService->register($instance, $club, $this->envelope($club, sprintf('Warmup %d', $n + 1)));
        }
        $this->em->refresh($instance);

        $future = new \DateTimeImmutable('+10 years');
        foreach ($this->roundRepository->findByCompetitionOrderedByIndex($instance) as $round) {
            $this->drawService->drawRoundIfClaimable($round, $future);
            $this->resultsService->publishResultsIfClaimable($round, $future);
        }
        $this->responseBuilder->sharedDocument($instance);
        $this->responseBuilder->stateFingerprint($instance);

        $this->cleanUp([$instance]);
    }

    /**
     * Plain clubs, created up front so registration can be timed without generation noise.
     *
     * Not spoof clubs: registration does not care, and this keeps the setup cost down to a
     * User and a Club insert rather than name and appearance generation.
     *
     * @return list<\App\Entity\Club>
     */
    private function makeClubs(int $count, int $competitionIndex): array
    {
        $clubs = [];
        for ($n = 1; $n <= $count; $n++) {
            $user = new User(sprintf('bench-%d-%d-%s%s', $competitionIndex, $n, bin2hex(random_bytes(4)), User::SPOOF_EMAIL_DOMAIN));
            $user->setPassword('x');
            $club = new \App\Entity\Club(sprintf('Bench %d-%d', $competitionIndex, $n), $user);
            $club->setBalance(100_000_000);
            $club->setSpoof(true);

            $this->em->persist($user);
            $this->em->persist($club);
            $clubs[] = $club;
        }
        $this->em->flush();

        return $clubs;
    }

    /**
     * A realistic submitted envelope: exactly SnapshotValidator::REQUIRED_PLAYERS players,
     * each carrying the attribute and appearance shape a real client sends, so the stored
     * squad and the resulting payload are representative rather than minimal.
     *
     * @return array<string, mixed>
     */
    private function envelope(\App\Entity\Club $club, string $name): array
    {
        $positions = ['GK', 'DEF', 'DEF', 'DEF', 'DEF', 'MID', 'MID', 'MID', 'ATT', 'ATT', 'ATT'];
        $players   = [];
        foreach ($positions as $i => $position) {
            $players[] = [
                'id'             => sprintf('%s-p%d', $club->getId(), $i),
                'name'           => sprintf('%s Player %d', $name, $i + 1),
                'position'       => $position,
                'currentAbility' => random_int(45, 85),
                'pace'           => random_int(40, 90),
                'technical'      => random_int(40, 90),
                'vision'         => random_int(40, 90),
                'power'          => random_int(40, 90),
                'stamina'        => random_int(40, 90),
                'heart'          => random_int(40, 90),
                'personality'    => ['consistency' => random_int(5, 18), 'temperament' => random_int(5, 18), 'pressure' => random_int(5, 18)],
                'appearance'     => [
                    'hair' => 'short', 'hairColor' => '#1a1a1a', 'headband' => false, 'skin' => 's3',
                    'face' => 'neutral', 'facial' => 'none', 'lip' => '#8d5524',
                ],
            ];
        }

        return [
            'club' => [
                'id'            => (string) $club->getId(),
                'name'          => $club->getName(),
                'formation'     => '4-4-2',
                'cohesion'      => random_int(40, 90),
                'managerMotivation' => random_int(40, 90),
            ],
            'players' => $players,
            'staff'   => [[
                'id' => 's1', 'role' => 'MANAGER', 'name' => $name . ' Boss', 'ability' => random_int(40, 85),
            ]],
        ];
    }

    /**
     * Turns the samples into the three settings the plan called guesses.
     *
     * @param list<float> $drawSamples
     * @param list<float> $resolveSamples
     */
    private function reportImplications(SymfonyStyle $io, array $drawSamples, array $resolveSamples): void
    {
        $io->section('What this implies for the tuning that was guessed');

        $worstDraw    = $drawSamples !== [] ? max($drawSamples) : 0.0;
        $worstResolve = $resolveSamples !== [] ? max($resolveSamples) : 0.0;
        $worstRound   = $worstDraw + $worstResolve;

        // A lease has to outlast the slowest single round by a wide margin, or a slow round
        // gets its own claim stolen mid-flight.
        $suggestedLease = max(60, (int) ceil(($worstRound / 1000) * 20 / 60) * 60);

        // A consumer run should fit comfortably inside its cron period (60s), so --limit is
        // bounded by how many of the worst-case messages fit in --time-limit.
        $suggestedLimit = max(1, (int) floor(50_000 / max(1.0, $worstResolve)));

        $io->definitionList(
            ['slowest draw' => $this->ms($worstDraw) . ' ms'],
            ['slowest resolve' => $this->ms($worstResolve) . ' ms'],
            ['slowest full round' => $this->ms($worstRound) . ' ms'],
            ['CLAIM_LEASE_SECONDS' => sprintf(
                'currently %d — measured worst round is %s ms, so %ds would already be ~%dx headroom',
                \App\Entity\Competition\CompetitionRound::CLAIM_LEASE_SECONDS,
                $this->ms($worstRound),
                $suggestedLease,
                $worstRound > 0 ? (int) (($suggestedLease * 1000) / $worstRound) : 0,
            )],
            ['competition-consume --limit' => sprintf(
                'currently 10 — at the measured worst resolve, ~%d messages fit in the 50s time limit',
                $suggestedLimit,
            )],
        );

        $io->note(
            "These are single-process timings on this machine against this dataset. They bound the\n"
            . "work per message, which is what --limit and the lease need. They do NOT measure\n"
            . "contention: nothing here runs two consumers against one competition, so the advisory\n"
            . "lock and the claim lease are still only argued for, not demonstrated."
        );
    }

    private function fixtureCount(\App\Entity\Competition\CompetitionRound $round): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM competition_fixture WHERE round_id = :id',
            ['id' => $round->getId()->toRfc4122()],
        );
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return array{ms: float, peak: int, value: mixed}
     */
    private function measure(callable $work): array
    {
        gc_collect_cycles();
        $before = memory_get_usage(true);
        $start  = microtime(true);
        $value  = $work();
        $ms     = (microtime(true) - $start) * 1000;

        return ['ms' => $ms, 'peak' => max(0, memory_get_usage(true) - $before), 'value' => $value];
    }

    private function ms(float $ms): string
    {
        return number_format($ms, 1);
    }

    private function pct(int $part, int $total): string
    {
        return $total > 0 ? number_format(($part / $total) * 100, 1) . '%' : 'n/a';
    }

    private function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1);
    }

    /**
     * Removes everything the run created: the competitions (which cascade to entrants,
     * squads, fixtures, results and audits) and the spoof clubs and users behind them.
     *
     * @param list<ActiveCompetition> $competitions
     */
    private function cleanUp(array $competitions): void
    {
        foreach ($competitions as $instance) {
            $competitionId = $instance->getId()->toRfc4122();

            $clubIds = $this->connection->fetchFirstColumn(
                'SELECT DISTINCT club_id FROM competition_entrant WHERE active_competition_id = :id',
                ['id' => $competitionId],
            );

            $templateId = $instance->getTemplate()->getId()->toRfc4122();

            $this->connection->executeStatement('DELETE FROM active_competition WHERE id = :id', ['id' => $competitionId]);
            $this->connection->executeStatement('DELETE FROM competition_template WHERE id = :id', ['id' => $templateId]);

            foreach ($clubIds as $clubId) {
                // Only ever spoof clubs here — spoofAllEntrants() creates them — but the
                // predicate is explicit rather than trusted, so a stray real club cannot be
                // deleted by a benchmark run.
                $this->connection->executeStatement(
                    'DELETE FROM club WHERE id = :id AND is_spoof = true',
                    ['id' => $clubId],
                );
            }

            // Spoof accounts are identified by User::SPOOF_EMAIL_DOMAIN, not by a prefix,
            // and only ones left with no club at all are removed — so this cannot reach a
            // real account or orphan a club that survived the delete above.
            $this->connection->executeStatement(
                'DELETE FROM "user" WHERE email LIKE :domain AND NOT EXISTS (SELECT 1 FROM club WHERE club.user_id = "user".id)',
                ['domain' => '%' . User::SPOOF_EMAIL_DOMAIN],
            );
        }

        $this->em->clear();
    }
}

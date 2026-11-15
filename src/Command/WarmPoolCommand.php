<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Country;
use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use App\Repository\PlayerRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Service\ClubInitializationService;
use App\Service\MarketPoolService;
use App\Repository\StarterConfigRepository;
use App\Service\StarterPackDiagnosticService;
use App\Service\StarterPackPoolWarmService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use App\Service\CountryContent\NamePoolProvider;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:pool:warm',
    description: 'Ensure the player, staff and scout pool has enough entities for each country\'s worldpack cache.',
)]
class WarmPoolCommand extends Command
{
    /**
     * THE STAFF ROLES THIS INSTALLATION ACTUALLY USES, READ FROM CONFIGURATION.
     *
     * ── WHAT THIS REPLACED, AND WHAT IT COST ──────────────────────────────────────────────
     *
     * A hardcoded `WORLDPACK_STAFF_ROLES = [MANAGER, COACH, CHAIRMAN]`, commented "roles
     * needed by WorldInitializationService when building NPC league packs" — a premise that
     * was wrong in both directions. The worldpack does NOT draw staff from the pool
     * (`WorldPackClubGenerationService::generateStaff()` builds its own), and the thing that
     * DOES draw from it — the starter pack — asks for five roles, not three.
     *
     * So `app:pool:warm` had never been asked to make a single native DIRECTOR_OF_FOOTBALL or
     * FACILITY_MANAGER. The only other path that makes them,
     * `GenerateMarketDataCommand::generateStaff()`, sets no nationality at all. On dev that
     * read as **0 English against 97 in pool** for exactly those two roles while every other
     * role had 50+.
     *
     * **And it showed GREEN**, because the starter-pack check falls back to foreign staff
     * when natives run out. Every English club quietly got a foreign director of football and
     * facility manager, and nothing on the screen said so. A hardcoded list of roles cannot
     * be wrong loudly — it can only be short.
     *
     * ── WHY BOTH CONFIGS ──────────────────────────────────────────────────────────────────
     *
     * The union of what the STARTER PACK asks for (`StarterConfig`'s per-role counts, via
     * `StarterPackDiagnosticService::staffRequirementsFor()` — the same source the check
     * screen reads, so the warmer and the page it is meant to satisfy cannot drift) and what
     * NPC TIERS ask for (`npcSquadConfig`). NPC clubs generate their own staff today rather
     * than drawing from the pool, so that half is belt-and-braces — but a role an operator
     * has configured anywhere is a role this pool should be able to serve, and tying the list
     * to one consumer is how it went short in the first place.
     *
     * A role configured at 0 everywhere is skipped: that is an operator saying they do not
     * use it, which is different from the list forgetting it exists.
     *
     * @return list<StaffRole>
     */
    private function configuredStaffRoles(): array
    {
        $starter = $this->starterConfigRepo->getConfig();
        $needed  = [];

        foreach (StarterPackDiagnosticService::staffRequirementsFor($starter) as $roleValue => $count) {
            if ($count > 0 && ($role = StaffRole::tryFrom($roleValue)) !== null) {
                $needed[$role->value] = $role;
            }
        }

        // The NPC side, keyed by the same `*Count` names the tier config uses.
        $npcKeys = [
            'managerCount'             => StaffRole::MANAGER,
            'coachCount'               => StaffRole::COACH,
            'chairmanCount'            => StaffRole::CHAIRMAN,
            'directorOfFootballCount'  => StaffRole::DIRECTOR_OF_FOOTBALL,
            'facilityManagerCount'     => StaffRole::FACILITY_MANAGER,
        ];

        foreach ($starter->getNpcSquadConfig() as $tierConf) {
            if (!is_array($tierConf)) {
                continue;
            }

            foreach ($npcKeys as $key => $role) {
                if ((int) ($tierConf[$key] ?? 0) > 0) {
                    $needed[$role->value] = $role;
                }
            }
        }

        // Stable order so two runs print the same thing — `StaffRole::cases()` order, not
        // discovery order, which would vary with the tier config's key order.
        $out = [];
        foreach (StaffRole::cases() as $role) {
            if (isset($needed[$role->value])) {
                $out[] = $role;
            }
        }

        return $out;
    }

    public function __construct(
        private readonly MarketPoolService $pool,
        private readonly PlayerRepository  $playerRepo,
        private readonly StaffRepository   $staffRepo,
        private readonly ScoutRepository   $scoutRepo,
        private readonly NamePoolProvider  $namePools,
        private readonly StarterPackPoolWarmService $starterPackWarm,
        private readonly StarterConfigRepository   $starterConfigRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('country', InputArgument::OPTIONAL, 'ISO country code (e.g. EN, ES). Omit to run all countries.')
            ->addOption('min-players',       null, InputOption::VALUE_REQUIRED, 'Minimum unassigned players per nationality',        500)
            ->addOption('min-staff',         null, InputOption::VALUE_REQUIRED, 'Minimum unassigned staff per role per nationality',  50)
            ->addOption('min-scouts',        null, InputOption::VALUE_REQUIRED, 'Minimum scouts per nationality',                     50)
            ->addOption('min-international', null, InputOption::VALUE_REQUIRED, 'Minimum players/staff/scouts for the foreign pool', 300)
            ->addOption('international',     null, InputOption::VALUE_NONE,     'Also warm the international (foreign-slot) pool')
            ->addOption('force',             null, InputOption::VALUE_NONE,     'Top up regardless of current counts')
            // TIER TARGETING. Without it this command generates from PoolConfig's flat
            // distribution, which aims at no tier and therefore cannot fill a tier band that
            // is empty — the reason pressing GENERATE on the starter pack check page never
            // moved the number. With it, players are generated the way the world pack
            // generates them: through PlayerGenerationService with the tier's ability band
            // and the first-team age range. Requires a country, because a band is a property
            // of (country, tier) and there is nothing to resolve without one.
            ->addOption('tier',              null, InputOption::VALUE_REQUIRED, 'Also generate players targeted at this league tier\'s ability band, so a starter pack for it can actually be drawn')
            ->addOption('squads',            null, InputOption::VALUE_REQUIRED, 'How many starter squads of headroom --tier should provision', StarterPackPoolWarmService::DEFAULT_SQUADS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io             = new SymfonyStyle($input, $output);
        $minPlayers     = (int) $input->getOption('min-players');
        $minStaff       = (int) $input->getOption('min-staff');
        $minScouts      = (int) $input->getOption('min-scouts');
        $minIntl        = (int) $input->getOption('min-international');
        $withIntl       = (bool) $input->getOption('international');
        $force          = (bool) $input->getOption('force');
        $countryArg     = $input->getArgument('country');
        $tierOpt        = $input->getOption('tier');
        $squads         = max(1, (int) $input->getOption('squads'));

        $tier = null;
        if ($tierOpt !== null) {
            $tier = (int) $tierOpt;
            if ($tier < 1 || $tier > 8) {
                $io->error("--tier must be between 1 and 8, got '{$tierOpt}'.");
                return Command::FAILURE;
            }
            if ($countryArg === null) {
                $io->error('--tier needs a country: an ability band belongs to a (country, tier) pair.');
                return Command::FAILURE;
            }
        }

        // ── Single-country mode (cron / targeted) ────────────────────────────
        if ($countryArg !== null) {
            $country     = strtoupper(trim((string) $countryArg));
            $nationality = ClubInitializationService::countryToNationality($country);

            if ($nationality === null) {
                $io->error("Unknown country code '{$country}'. Supported: " . implode(' ', array_keys(Country::nationalityMap())));
                return Command::FAILURE;
            }

            $status = $this->warmCountry($io, $country, $nationality, $minPlayers, $minStaff, $minScouts, $force);

            // AFTER the flat top-up, never instead of it. The flat pool still has to exist —
            // it is what serves browsing and youth at every tier — and running second means
            // the deficit is measured against the pool as it will actually be when the draw
            // happens, rather than against a pool that is about to grow.
            if ($status === Command::SUCCESS && $tier !== null) {
                $status = $this->warmTier($io, $country, $tier, $squads);
            }

            return $status;
        }

        // ── All-countries mode ────────────────────────────────────────────────
        $failed = 0;
        foreach (Country::nationalityMap() as $country => $nationality) {
            $result = $this->warmCountry($io, $country, $nationality, $minPlayers, $minStaff, $minScouts, $force);
            if ($result !== Command::SUCCESS) {
                $failed++;
            }
        }

        // ── International pool (foreign slots across all worldpacks) ──────────
        if ($withIntl) {
            $intlResult = $this->warmInternationalPool($io, $minIntl, $force);
            if ($intlResult !== Command::SUCCESS) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $io->error("Pool warming completed with {$failed} failure(s).");
            return Command::FAILURE;
        }

        $io->success('Pool warming complete.');
        return Command::SUCCESS;
    }

    // ── Per-country warming ───────────────────────────────────────────────────

    private function warmCountry(
        SymfonyStyle $io,
        string       $country,
        string       $nationality,
        int          $minPlayers,
        int          $minStaff,
        int          $minScouts,
        bool         $force,
    ): int {
        $io->section("[{$country}] {$nationality}");

        // SAID ONCE PER COUNTRY, which is the whole point of putting it here. generateName()
        // used to throw for a nationality with no pool — loud, but once per PERSON and only
        // into a stack trace, and it took the whole command's exit code with it. The caller
        // that knows it is about to make five hundred of something is the right place to
        // mention that they will all be named in English.
        if (!$this->namePools->has($nationality)) {
            $io->warning("No name pool for '{$nationality}' — generated people will carry English names.");
        }

        $peakStart = memory_get_peak_usage(true);

        try {
            // Players
            $currentPlayers = $this->playerRepo->countInPoolByNationality($nationality);
            if ($force || $currentPlayers < $minPlayers) {
                $needed = $force ? $minPlayers : $minPlayers - $currentPlayers;
                $io->text("  Players: {$currentPlayers} in pool → generating {$needed}");
                $this->pool->generatePlayers($needed, RecruitmentSource::YOUTH_INTAKE, $nationality);
            } else {
                $io->text("  Players: {$currentPlayers} in pool — OK (min {$minPlayers})");
            }

            // Staff — every role the starter pack or an NPC tier is configured to use.
            foreach ($this->configuredStaffRoles() as $role) {
                $current = $this->staffRepo->countInPoolByNationalityAndRole($nationality, $role);
                if ($force || $current < $minStaff) {
                    $needed = $force ? $minStaff : $minStaff - $current;
                    $io->text("  {$role->value}: {$current} in pool → generating {$needed}");
                    $this->pool->generateStaffForRole($role, $needed, $nationality);
                } else {
                    $io->text("  {$role->value}: {$current} in pool — OK (min {$minStaff})");
                }
            }

            // Scouts
            $currentScouts = $this->scoutRepo->countByNationality($nationality);
            if ($force || $currentScouts < $minScouts) {
                $needed = $force ? $minScouts : $minScouts - $currentScouts;
                $io->text("  Scouts: {$currentScouts} in pool → generating {$needed}");
                $this->pool->generateScouts($needed, $nationality);
            } else {
                $io->text("  Scouts: {$currentScouts} in pool — OK (min {$minScouts})");
            }

            $peakMb = round((memory_get_peak_usage(true) - $peakStart) / 1024 / 1024, 1);
            $io->text("  Done. Peak Δmem: {$peakMb}MB");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error("[{$country}] FAILED: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    // ── Tier-targeted players ─────────────────────────────────────────────────

    /**
     * Reports the band it generated into and what each position ended up with, because the
     * two ways this can disappoint an operator are invisible otherwise: a band resolved from
     * a fallback rather than from the country's own authored ranges, and a band the generator
     * cannot reliably hit.
     */
    private function warmTier(SymfonyStyle $io, string $country, int $tier, int $squads): int
    {
        $io->section("[{$country}] tier {$tier} — band-targeted players");

        try {
            $result = $this->starterPackWarm->warmForTier($country, $tier, $squads);
        } catch (\Throwable $e) {
            $io->error("[{$country}] tier {$tier} FAILED: " . $e->getMessage());

            return Command::FAILURE;
        }

        $band = $result['band'];
        $age  = $result['ageRange'];

        $io->text(sprintf(
            '  Band %d-%d (%s), ages %d-%d, nationality %s, %d squad(s) of headroom',
            $band['min'], $band['max'], $band['source'], $age['min'], $age['max'],
            $result['nationality'], $result['squads'],
        ));

        $rows = [];
        foreach ($result['positions'] as $row) {
            $rows[] = [
                $row['position'],
                $row['available'],
                $row['target'],
                $row['deficit'],
                $row['generated'] . ($row['capped'] ? ' (capped)' : ''),
                $row['attempts'],
            ];
        }
        $io->table(['pos', 'had', 'target', 'deficit', 'generated', 'draws'], $rows);

        if ($result['shortOfTarget']) {
            $io->warning(sprintf(
                'Some positions generated fewer players than asked for: the %d-%d band is hard to '
                . 'hit, because realised ability is a fraction of potential and the band is applied '
                . 'as a potential range. Widening the band, or lowering its floor, is the fix — not '
                . 'running this again.',
                $band['min'],
                $band['max'],
            ));
        }

        $io->text(sprintf('  Generated %d player(s) from %d draw(s).', $result['generated'], $result['attempts']));

        return Command::SUCCESS;
    }

    // ── International pool ────────────────────────────────────────────────────

    /**
     * The "foreign" pool: players/staff/scouts of mixed nationalities that worldpack
     * draws on for the non-domestic squad slots in every league. Generated without a
     * nationality lock so NameGeneratorService distributes across its full list.
     */
    private function warmInternationalPool(
        SymfonyStyle $io,
        int          $minPerType,
        bool         $force,
    ): int {
        $io->section('[INTERNATIONAL] Foreign-slot pool');

        // Count pool entities whose nationality is NOT one of the 19 mapped ones.
        $mappedNationalities = array_values(Country::nationalityMap());

        try {
            // Players
            $foreignPlayers = $this->countForeignPlayers($mappedNationalities);
            if ($force || $foreignPlayers < $minPerType) {
                $needed = $force ? $minPerType : $minPerType - $foreignPlayers;
                $io->text("  Players: {$foreignPlayers} foreign in pool → generating {$needed} (random nationality)");
                // null nationality → NameGeneratorService picks randomly from its full list
                $this->pool->generatePlayers($needed, RecruitmentSource::YOUTH_INTAKE);
            } else {
                $io->text("  Players: {$foreignPlayers} foreign in pool — OK (min {$minPerType})");
            }

            // Staff
            foreach ($this->configuredStaffRoles() as $role) {
                $foreignStaff = $this->countForeignStaff($mappedNationalities, $role);
                if ($force || $foreignStaff < $minPerType) {
                    $needed = $force ? $minPerType : $minPerType - $foreignStaff;
                    $io->text("  {$role->value}: {$foreignStaff} foreign in pool → generating {$needed}");
                    $this->pool->generateStaffForRole($role, $needed);
                } else {
                    $io->text("  {$role->value}: {$foreignStaff} foreign in pool — OK (min {$minPerType})");
                }
            }

            // Scouts
            $foreignScouts = $this->countForeignScouts($mappedNationalities);
            if ($force || $foreignScouts < $minPerType) {
                $needed = $force ? $minPerType : $minPerType - $foreignScouts;
                $io->text("  Scouts: {$foreignScouts} foreign in pool → generating {$needed}");
                $this->pool->generateScouts($needed);
            } else {
                $io->text("  Scouts: {$foreignScouts} foreign in pool — OK (min {$minPerType})");
            }

            $io->text('  Done.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('[INTERNATIONAL] FAILED: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    // ── Foreign-entity counters ───────────────────────────────────────────────

    /** @param string[] $exclude */
    private function countForeignPlayers(array $exclude): int
    {
        return (int) $this->playerRepo->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.nationality NOT IN (:nats)')
            ->setParameter('nats', $exclude)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param string[] $exclude */
    private function countForeignStaff(array $exclude, StaffRole $role): int
    {
        return (int) $this->staffRepo->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.role = :role')
            ->andWhere('s.nationality NOT IN (:nats)')
            ->setParameter('role', $role)
            ->setParameter('nats', $exclude)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param string[] $exclude */
    private function countForeignScouts(array $exclude): int
    {
        return (int) $this->scoutRepo->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.nationality NOT IN (:nats)')
            ->setParameter('nats', $exclude)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

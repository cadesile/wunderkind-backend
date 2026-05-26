<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use App\Repository\PlayerRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Service\ClubInitializationService;
use App\Service\MarketPoolService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
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
    // Roles needed by WorldInitializationService when building NPC league packs.
    private const WORLDPACK_STAFF_ROLES = [
        StaffRole::MANAGER,
        StaffRole::COACH,
        StaffRole::CHAIRMAN,
    ];

    private const SUPPORTED_COUNTRIES = [
        'EN' => 'English',
        'IT' => 'Italian',
        'DE' => 'German',
        'ES' => 'Spanish',
        'BR' => 'Brazilian',
        'AR' => 'Argentine',
        'NL' => 'Dutch',
        'FR' => 'French',
        'PT' => 'Portuguese',
        'NG' => 'Nigerian',
        'GH' => 'Ghanaian',
        'JP' => 'Japanese',
        'KR' => 'South Korean',
        'SE' => 'Swedish',
        'DK' => 'Danish',
        'IE' => 'Irish',
        'CI' => 'Ivorian',
        'SN' => 'Senegalese',
        'CN' => 'Chinese',
    ];

    public function __construct(
        private readonly MarketPoolService $pool,
        private readonly PlayerRepository  $playerRepo,
        private readonly StaffRepository   $staffRepo,
        private readonly ScoutRepository   $scoutRepo,
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
            ->addOption('force',             null, InputOption::VALUE_NONE,     'Top up regardless of current counts');
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

        // ── Single-country mode (cron / targeted) ────────────────────────────
        if ($countryArg !== null) {
            $country     = strtoupper(trim((string) $countryArg));
            $nationality = ClubInitializationService::countryToNationality($country);

            if ($nationality === null) {
                $io->error("Unknown country code '{$country}'. Supported: " . implode(' ', array_keys(self::SUPPORTED_COUNTRIES)));
                return Command::FAILURE;
            }

            return $this->warmCountry($io, $country, $nationality, $minPlayers, $minStaff, $minScouts, $force);
        }

        // ── All-countries mode ────────────────────────────────────────────────
        $failed = 0;
        foreach (self::SUPPORTED_COUNTRIES as $country => $nationality) {
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

            // Staff (manager / coach / chairman)
            foreach (self::WORLDPACK_STAFF_ROLES as $role) {
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
        $mappedNationalities = array_values(self::SUPPORTED_COUNTRIES);

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
            foreach (self::WORLDPACK_STAFF_ROLES as $role) {
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
            ->where('p.club IS NULL')
            ->andWhere('p.nationality NOT IN (:nats)')
            ->setParameter('nats', $exclude)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param string[] $exclude */
    private function countForeignStaff(array $exclude, StaffRole $role): int
    {
        return (int) $this->staffRepo->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.club IS NULL')
            ->andWhere('s.role = :role')
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

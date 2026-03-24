<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Academy;
use App\Entity\Agent;
use App\Entity\Investor;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Sponsor;
use App\Entity\Staff;
use App\Enum\CompanySize;
use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use App\Repository\AgentRepository;
use App\Repository\InvestorRepository;
use App\Repository\PlayerRepository;
use App\Repository\ScoutRepository;
use App\Repository\SponsorRepository;
use App\Repository\StaffRepository;
use Doctrine\ORM\EntityManagerInterface;

class MarketPoolService
{
    // Replenishment thresholds
    private const PLAYER_THRESHOLD   = 50;
    private const COACH_THRESHOLD    = 10;
    private const SCOUT_THRESHOLD    = 5;
    private const SPONSOR_THRESHOLD  = 10;
    private const INVESTOR_THRESHOLD = 5;


    private const INVESTOR_COMPANIES = [
        'RedBird Capital Partners', 'Oaktree Capital Management', 'Elliott Management',
        'Silver Lake Partners', 'CVC Capital Partners', 'Clearlake Capital Group',
        'KKR & Co', 'Blackstone Group', 'Apollo Global Management', 'Bain Capital',
        'TPG Capital', 'Warburg Pincus', 'General Atlantic', 'Advent International',
        'Permira Advisers', 'BC Partners', 'PAI Partners', 'Cinven Partners',
        'EQT Partners', 'Ardian Investment',
    ];

    private const SPONSOR_COMPANIES = [
        'Nike', 'Adidas', 'Puma', 'Under Armour', 'New Balance', 'Umbro',
        'Emirates', 'Etihad Airways', 'Qatar Airways', 'Turkish Airlines',
        'Coca-Cola', 'Pepsi', 'Red Bull', 'Monster Energy', 'Heineken',
        'EA Sports', 'Samsung', 'Sony', 'BMW', 'Audi', 'Mercedes-Benz',
        'Visa', 'Mastercard', 'PayPal', 'Rolex', 'TAG Heuer',
    ];

    /** Country-code-keyed male name pools for player generation. */
    private const MALE_NAMES = [
        'EN' => [
            'first' => ['Jack','Harry','George','Oliver','Charlie','Alfie','Freddie','Archie','Tommy','Louie','Mason','Theo','Finley','Elliot','Reuben','Dylan','Callum','Kieran','Jamie','Ryan'],
            'last'  => ['Smith','Jones','Williams','Taylor','Brown','Davies','Evans','Wilson','Thomas','Roberts','Walker','White','Thompson','Hughes','Martin','Clarke','Hall','Wood','Jackson','Harris'],
        ],
        'IT' => [
            'first' => ['Luca','Matteo','Leonardo','Francesco','Alessandro','Lorenzo','Giacomo','Andrea','Davide','Riccardo','Edoardo','Filippo','Marco','Simone','Federico','Nicola','Pietro','Giovanni','Stefano','Tommaso'],
            'last'  => ['Rossi','Ferrari','Russo','Bianchi','Colombo','Bruni','Conti','De Luca','Costa','Mancini','Ricci','Marino','Greco','Bruno','Gallo','Conte','Lombardi','Moretti','Barbieri','Fontana'],
        ],
        'DE' => [
            'first' => ['Leon','Lukas','Felix','Jonas','Finn','Noah','Elias','Maximilian','Paul','Ben','Tobias','Jan','Niklas','Tim','Moritz','Sebastian','Fabian','Philipp','Julian','Florian'],
            'last'  => ['Müller','Schmidt','Schneider','Fischer','Weber','Meyer','Wagner','Becker','Schulz','Hoffmann','Schäfer','Koch','Bauer','Richter','Klein','Wolf','Schröder','Neumann','Schwarz','Zimmermann'],
        ],
        'ES' => [
            'first' => ['Alejandro','Pablo','Daniel','Carlos','Javier','Miguel','Sergio','Adrián','Marcos','Álvaro','Diego','Rubén','Iván','Raúl','Hugo','Iker','Borja','Unai','Aitor','Jon'],
            'last'  => ['García','Martínez','López','Sánchez','González','Pérez','Rodríguez','Fernández','Torres','Ramírez','Flores','Moreno','Jiménez','Ruiz','Díaz','Hernández','Romero','Alonso','Navarro','Molina'],
        ],
        'BR' => [
            'first' => ['Gabriel','Lucas','Mateus','Pedro','Guilherme','Rafael','Felipe','Bruno','Thiago','André','Vinicius','Rodrigo','Diego','Caio','Leandro','Renan','Igor','Renato','Gustavo','Henrique'],
            'last'  => ['Silva','Santos','Oliveira','Souza','Rodrigues','Ferreira','Alves','Pereira','Lima','Carvalho','Gomes','Martins','Costa','Ribeiro','Nascimento','Araújo','Moreira','Nunes','Barbosa','Cavalcanti'],
        ],
        'AR' => [
            'first' => ['Matías','Nicolás','Facundo','Agustín','Sebastián','Maximiliano','Rodrigo','Ezequiel','Leandro','Cristian','Lucas','Federico','Gonzalo','Martín','Pablo','Ignacio','Hernán','Ramiro','Emiliano','Santiago'],
            'last'  => ['González','Rodríguez','Gómez','Fernández','López','Díaz','Martínez','Pérez','García','Sánchez','Romero','Torres','Flores','Acosta','Medina','Herrera','Aguirre','Morales','Suárez','Delgado'],
        ],
        'NL' => [
            'first' => ['Daan','Sem','Finn','Levi','Luuk','Thijs','Ruben','Lars','Bram','Joris','Sander','Tim','Niels','Bas','Stef','Wouter','Jasper','Rick','Milan','Robin'],
            'last'  => ['De Jong','Janssen','De Vries','Van den Berg','Van Dijk','Bakker','Visser','Smit','Meijer','De Boer','Mulder','De Graaf','Bos','Hendriks','Van Leeuwen','Peters','Dekker','Brouwer','Kok','Kuiper'],
        ],
        '_fallback' => [
            'first' => ['Kai','Omar','Ryo','Yusuf','Kwame','Alexis','Emil','Ivan','Dami','Noa'],
            'last'  => ['Okafor','Park','Mensah','Boateng','Nakamura','Dupont','Andersen','Makinen','Afolabi','Svensson'],
        ],
    ];

    /** Maps nationality strings (stored on the Player entity) to MALE_NAMES country codes. */
    private const NATIONALITY_TO_CODE = [
        'English'   => 'EN',
        'Italian'   => 'IT',
        'German'    => 'DE',
        'Spanish'   => 'ES',
        'Brazilian' => 'BR',
        'Argentine' => 'AR',
        'Dutch'     => 'NL',
    ];

    private const ATTRIBUTE_KEYS = ['pace', 'technical', 'vision', 'power', 'stamina', 'heart'];

    /** Position-weighted attribute ranges: [min, max] per attribute */
    private const POSITION_ATTRIBUTES = [
        'DEF' => ['stamina' => [10, 30], 'heart' => [10, 30], 'power' => [10, 30], 'pace' => [5, 20], 'technical' => [5, 20], 'vision' => [5, 20]],
        'GK'  => ['stamina' => [10, 30], 'heart' => [10, 30], 'power' => [10, 30], 'pace' => [5, 20], 'technical' => [5, 20], 'vision' => [5, 20]],
        'MID' => ['vision' => [10, 30], 'technical' => [8, 25], 'stamina' => [8, 25], 'pace' => [5, 20], 'power' => [5, 20], 'heart' => [5, 20]],
        'ATT' => ['pace' => [10, 30], 'technical' => [10, 30], 'power' => [8, 25], 'vision' => [5, 20], 'stamina' => [5, 20], 'heart' => [5, 20]],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlayerRepository       $playerRepo,
        private readonly StaffRepository        $staffRepo,
        private readonly ScoutRepository        $scoutRepo,
        private readonly AgentRepository        $agentRepo,
        private readonly SponsorRepository      $sponsorRepo,
        private readonly InvestorRepository     $investorRepo,
        private readonly NameGeneratorService   $nameGenerator,
    ) {}

    // -------------------------------------------------------------------------
    // Generate
    // -------------------------------------------------------------------------

    /** @return Player[] */
    public function generatePlayers(int $count, ?int $academyReputation = null, RecruitmentSource $source = RecruitmentSource::YOUTH_INTAKE, ?string $nationality = null): array
    {
        $agents      = $this->agentRepo->findAll();
        $multipliers = $this->getWageMultiplier($academyReputation);
        $players     = [];

        for ($i = 0; $i < $count; $i++) {
            $potential      = $this->bellCurveInt(40, 80, 60);
            $currentAbility = random_int(3, 10);
            $age            = random_int(12, 13);
            $nat       = $nationality ?? $this->nameGenerator->getRandomNationality();
            $code      = self::NATIONALITY_TO_CODE[$nat] ?? '_fallback';
            $firstName = $this->pickName($code, 'first');
            $lastName  = $this->pickName($code, 'last');

            $player = new Player(
                firstName:         $firstName,
                lastName:          $lastName,
                dateOfBirth:       $this->dobFromAge($age),
                nationality:       $nat,
                position:          $this->weightedPosition(),
                recruitmentSource: $source,
                potential:         $potential,
                currentAbility:    $currentAbility,
                academy:           null,
            );

            $player->setStatus(PlayerStatus::ACTIVE);
            $baseWage = $currentAbility * random_int(10, 40);
            $player->setContractValue((int) ($baseWage * $multipliers['player']));

            // Distribute a total attribute budget of 6–20 across 6 attributes, position-weighted
            $attrBudget = random_int(6, 20);
            $attrs      = $this->distributeAttributes($player->getPosition(), $attrBudget);
            $player->setPace($attrs['pace']);
            $player->setTechnical($attrs['technical']);
            $player->setVision($attrs['vision']);
            $player->setPower($attrs['power']);
            $player->setStamina($attrs['stamina']);
            $player->setHeart($attrs['heart']);

            // Physical measurements (youth players aged 12-13)
            $player->setHeight(random_int(145, 160));
            $player->setWeight(random_int(38, 55));

            if (!empty($agents) && random_int(1, 100) <= 40) {
                $player->setAgent($agents[array_rand($agents)]);
            }

            $p = $player->getPersonality();
            $p->setConfidence(random_int(30, 70));
            $p->setMaturity(random_int(30, 70));
            $p->setTeamwork(random_int(30, 70));
            $p->setLeadership(random_int(30, 70));
            $p->setEgo(random_int(30, 70));
            $p->setBravery(random_int(30, 70));
            $p->setGreed(random_int(30, 70));
            $p->setLoyalty(random_int(30, 70));

            $this->em->persist($player);
            $players[] = $player;

            if ($i > 0 && $i % 50 === 0) {
                $this->em->flush();
                $this->em->clear(Player::class);
                $agents = $this->agentRepo->findAll(); // re-fetch after clear
            }
        }

        $this->em->flush();
        return $players;
    }

    /** @return Staff[] */
    public function generateCoaches(int $count, ?int $academyReputation = null): array
    {
        $coachRoles = [
            StaffRole::HEAD_COACH,
            StaffRole::ASSISTANT_COACH,
            StaffRole::FITNESS_COACH,
            StaffRole::ANALYST,
        ];

        $multipliers = $this->getWageMultiplier($academyReputation);
        $coaches     = [];

        for ($i = 0; $i < $count; $i++) {
            $role    = $coachRoles[array_rand($coachRoles)];
            $ability = random_int(40, 75);
            $coachName = $this->nameGenerator->generateName($this->nameGenerator->getRandomNationality());
            [$coachFirst, $coachLast] = array_pad(explode(' ', $coachName, 2), 2, '');

            $staff = new Staff(
                firstName: $coachFirst,
                lastName:  $coachLast,
                role:      $role,
                academy:   null,
            );

            $staff->setCoachingAbility($ability);
            $staff->setScoutingRange(random_int(40, 75));
            $staff->setSpecialisms($this->generateSpecialisms());

            $baseSalary = match ($role) {
                StaffRole::HEAD_COACH      => random_int(8000, 20000),
                StaffRole::ASSISTANT_COACH => random_int(4000, 10000),
                StaffRole::SCOUT           => random_int(2500, 7000),
                StaffRole::FITNESS_COACH   => random_int(3000, 8000),
                StaffRole::ANALYST         => random_int(3000, 7500),
            };
            $staff->setWeeklySalary((int) ($baseSalary * $multipliers['staff']));

            $this->em->persist($staff);
            $coaches[] = $staff;

            if ($i > 0 && $i % 50 === 0) {
                $this->em->flush();
                $this->em->clear(Staff::class);
            }
        }

        $this->em->flush();
        return $coaches;
    }

    /** @return Scout[] */
    public function generateScouts(int $count): array
    {
        $scouts = [];

        for ($i = 0; $i < $count; $i++) {
            $age         = random_int(28, 40);
            $experience  = random_int(0, 10);
            $scoutNat    = $this->nameGenerator->getRandomNationality();
            $scoutName   = $this->nameGenerator->generateName($scoutNat);

            $scout = new Scout($scoutName);
            $scout->setDob($this->dobFromAge($age));
            $scout->setNationality($scoutNat);
            $scout->setExperience($experience);
            $scout->setJudgements([
                'potential'   => random_int(40, 80),
                'technical'   => random_int(40, 80),
                'physical'    => random_int(40, 80),
                'mental'      => random_int(40, 80),
                'personality' => random_int(40, 80),
            ]);

            $this->em->persist($scout);
            $scouts[] = $scout;
        }

        $this->em->flush();
        return $scouts;
    }

    /** @return Agent[] */
    public function generateAgents(int $count): array
    {
        $agents = [];

        for ($i = 0; $i < $count; $i++) {
            $reputation = random_int(30, 70);
            $rating     = max(1, min(100, $reputation + random_int(-10, 10)));
            $experience = max(5, $reputation - random_int(5, 15));
            $age        = random_int(30, 60);
            $agentNat   = $this->nameGenerator->getRandomNationality();
            $agentName  = $this->nameGenerator->generateName($agentNat);

            $commissionBase = (int) (800 + ($reputation / 100) * 700);
            $commissionRate = number_format(random_int($commissionBase, $commissionBase + 400) / 100, 2);

            $agent = new Agent($agentName);
            $agent->setReputation($reputation);
            $agent->setRating($rating);
            $agent->setExperience($experience);
            $agent->setCommissionRate($commissionRate);
            $agent->setDob($this->dobFromAge($age));
            $agent->setNationality($agentNat);
            $agent->setJudgements([
                'potential'   => random_int(40, 85),
                'current'     => random_int(40, 85),
                'personality' => random_int(40, 85),
                'technical'   => random_int(40, 85),
                'physical'    => random_int(40, 85),
            ]);

            $this->em->persist($agent);
            $agents[] = $agent;
        }

        $this->em->flush();
        return $agents;
    }

    /** @return Sponsor[] */
    public function generateSponsors(int $count, CompanySize $preferredSize = CompanySize::SMALL): array
    {
        $companies = $this->pickUnique(self::SPONSOR_COMPANIES, $count);
        $sponsors  = [];

        for ($i = 0; $i < $count; $i++) {
            $sponsor = new Sponsor($companies[$i]);
            $sponsor->setNationality($this->nameGenerator->getRandomNationality());
            $sponsor->setSize($this->sizeWithVariance($preferredSize));
            $sponsor->setIsActive(true);

            $this->em->persist($sponsor);
            $sponsors[] = $sponsor;
        }

        $this->em->flush();
        return $sponsors;
    }

    /** @return Investor[] */
    public function generateInvestors(int $count, CompanySize $preferredSize = CompanySize::SMALL): array
    {
        $companies = $this->pickUnique(self::INVESTOR_COMPANIES, $count);
        $investors = [];

        for ($i = 0; $i < $count; $i++) {
            $investor = new Investor($companies[$i]);
            $investor->setNationality($this->nameGenerator->getRandomNationality());
            $investor->setSize($this->sizeWithVariance($preferredSize));
            $investor->setIsActive(true);

            $this->em->persist($investor);
            $investors[] = $investor;
        }

        $this->em->flush();
        return $investors;
    }

    // -------------------------------------------------------------------------
    // Fetch
    // -------------------------------------------------------------------------

    /** @return Player[] Unassigned YOUTH_INTAKE players for the open market */
    public function getAvailablePlayers(int $limit = 100, ?string $nationality = null): array
    {
        return $this->playerRepo->findInPool($limit, $nationality);
    }

    /** @return Player[] Unassigned SCOUTING_NETWORK players for the scout prospect pool */
    public function getAvailableProspects(int $limit = 150): array
    {
        return $this->playerRepo->findProspects($limit);
    }

    /** @return Staff[] */
    public function getAvailableCoaches(int $limit = 20): array
    {
        return $this->staffRepo->findInPool(null, $limit);
    }

    /** @return Scout[] */
    public function getAvailableScouts(int $limit = 10): array
    {
        return $this->scoutRepo->findAll();
    }

    /** @return Agent[] */
    public function getAgents(): array
    {
        return $this->agentRepo->findAll();
    }

    /** @return Sponsor[] */
    public function getAvailableSponsorPool(int $limit = 20): array
    {
        return $this->sponsorRepo->findInPool($limit);
    }

    /** @return Investor[] */
    public function getAvailableInvestorPool(int $limit = 10): array
    {
        return $this->investorRepo->findInPool($limit);
    }

    // -------------------------------------------------------------------------
    // Assign
    // -------------------------------------------------------------------------

    /**
     * Assign a pool entity to an academy.
     *
     * For Scout entities, a corresponding Staff(SCOUT) member is created on
     * the academy rather than modifying the Scout entity itself (scouts are
     * always globally available as a reference pool).
     *
     * @throws \RuntimeException if a Player/Staff/Investor/Sponsor is already assigned
     */
    public function assignToAcademy(mixed $entity, Academy $academy): void
    {
        $now = new \DateTimeImmutable();

        if ($entity instanceof Player) {
            if (!$entity->isInMarketPool()) {
                throw new \RuntimeException('Player is already assigned to an academy.');
            }
            $entity->setAcademy($academy);
            $entity->setAssignedAt($now);
            $this->em->flush();
            return;
        }

        if ($entity instanceof Staff) {
            if (!$entity->isInMarketPool()) {
                throw new \RuntimeException('Staff member is already assigned to an academy.');
            }
            $entity->setAcademy($academy);
            $entity->setAssignedAt($now);
            $this->em->flush();
            return;
        }

        if ($entity instanceof Scout) {
            // Scouts are a reference pool — "hiring" one creates a Staff(SCOUT) entry
            $nameParts = explode(' ', $entity->getName(), 2);
            $staff = new Staff(
                firstName: $nameParts[0],
                lastName:  $nameParts[1] ?? $nameParts[0],
                role:      StaffRole::SCOUT,
                academy:   $academy,
            );
            $staff->setScoutingRange(min(100, (int) ($entity->getExperience() * 4) + 30));
            $this->em->persist($staff);
            $this->em->flush();
            return;
        }

        if ($entity instanceof Sponsor) {
            if (!$entity->isInMarketPool()) {
                throw new \RuntimeException('Sponsor is already assigned to an academy.');
            }
            $entity->setAcademy($academy);
            $entity->setAssignedAt($now);
            $this->em->flush();
            return;
        }

        if ($entity instanceof Investor) {
            if (!$entity->isInMarketPool()) {
                throw new \RuntimeException('Investor is already assigned to an academy.');
            }
            $entity->setAcademy($academy);
            $entity->setAssignedAt($now);
            $this->em->flush();
            return;
        }

        throw new \InvalidArgumentException('Unknown entity type for assignment.');
    }

    // -------------------------------------------------------------------------
    // Replenishment
    // -------------------------------------------------------------------------

    public function replenishPool(): array
    {
        $generated = [];

        if ($this->playerRepo->countInPool() < self::PLAYER_THRESHOLD) {
            $this->generatePlayers(self::PLAYER_THRESHOLD);
            $generated[] = self::PLAYER_THRESHOLD . ' players';
        }

        if ($this->staffRepo->countInPool() < self::COACH_THRESHOLD) {
            $this->generateCoaches(self::COACH_THRESHOLD);
            $generated[] = self::COACH_THRESHOLD . ' coaches';
        }

        if ($this->scoutRepo->count([]) < self::SCOUT_THRESHOLD) {
            $this->generateScouts(self::SCOUT_THRESHOLD);
            $generated[] = self::SCOUT_THRESHOLD . ' scouts';
        }

        if ($this->sponsorRepo->countInPool() < self::SPONSOR_THRESHOLD) {
            $this->generateSponsors(self::SPONSOR_THRESHOLD);
            $generated[] = self::SPONSOR_THRESHOLD . ' sponsors';
        }

        if ($this->investorRepo->countInPool() < self::INVESTOR_THRESHOLD) {
            $this->generateInvestors(self::INVESTOR_THRESHOLD);
            $generated[] = self::INVESTOR_THRESHOLD . ' investors';
        }

        return $generated;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns wage multipliers based on academy reputation tier.
     * When no academy context is provided (global pool generation), returns base multipliers (1.0).
     *
     * @return array{player: float, staff: float}
     */
    private function getWageMultiplier(?int $academyReputation): array
    {
        if ($academyReputation === null) {
            return ['player' => 1.0, 'staff' => 1.0];
        }

        return match (true) {
            $academyReputation < 100 => ['player' => 0.5,  'staff' => 0.6],
            $academyReputation < 300 => ['player' => 1.0,  'staff' => 1.0],
            $academyReputation < 600 => ['player' => 2.5,  'staff' => 2.0],
            default                  => ['player' => 5.0,  'staff' => 4.0],
        };
    }

    private function pickName(string $countryCode, string $type): string
    {
        $pool  = self::MALE_NAMES[$countryCode] ?? self::MALE_NAMES['_fallback'];
        $names = $pool[$type];
        return $names[array_rand($names)];
    }

    private function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    private function pickUnique(array $pool, int $n): array
    {
        shuffle($pool);
        if ($n <= count($pool)) {
            return array_slice($pool, 0, $n);
        }
        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $base     = $pool[$i % count($pool)];
            $result[] = $i < count($pool) ? $base : $base . ' ' . ($i + 1);
        }
        return $result;
    }

    private function dobFromAge(int $age): \DateTimeImmutable
    {
        $year  = (int) date('Y') - $age;
        $month = random_int(1, 12);
        $day   = random_int(1, 28);
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * Bell-curve integer: average of two rand calls approximates a normal distribution.
     */
    private function bellCurveInt(int $min, int $max, int $mean): int
    {
        $a = random_int($min, $max);
        $b = random_int($min, $max);
        $raw = (int) round(($a + $b) / 2);
        // Nudge slightly toward mean
        return (int) round(($raw + $mean) / 2);
    }


    /** GK 8 % / DEF 30 % / MID 38 % / ATT 24 % */
    private function weightedPosition(): PlayerPosition
    {
        $r = random_int(1, 100);
        return match (true) {
            $r <= 8  => PlayerPosition::GOALKEEPER,
            $r <= 38 => PlayerPosition::DEFENDER,
            $r <= 76 => PlayerPosition::MIDFIELDER,
            default  => PlayerPosition::ATTACKER,
        };
    }

    /**
     * Distribute a total attribute budget across the 6 attributes, weighted by position.
     * Ensures the sum equals $total exactly (max 20 for youth pool players).
     *
     * @return array{pace: int, technical: int, vision: int, power: int, stamina: int, heart: int}
     */
    private function distributeAttributes(PlayerPosition $position, int $total): array
    {
        $posKey = $position->value;
        $ranges = self::POSITION_ATTRIBUTES[$posKey] ?? self::POSITION_ATTRIBUTES['MID'];

        // Use midpoint of each position range as the weight for that attribute
        $weights    = [];
        $totalWeight = 0.0;
        foreach (self::ATTRIBUTE_KEYS as $key) {
            $w             = ($ranges[$key][0] + $ranges[$key][1]) / 2.0;
            $weights[$key] = $w;
            $totalWeight  += $w;
        }

        // Proportional allocation with remainder assigned to the highest-weighted attribute
        $attrs     = [];
        $allocated = 0;
        $keys      = self::ATTRIBUTE_KEYS;

        foreach ($keys as $idx => $key) {
            if ($idx === count($keys) - 1) {
                $attrs[$key] = max(0, $total - $allocated);
            } else {
                $share       = (int) round($total * $weights[$key] / $totalWeight);
                $share       = min($share, $total - $allocated);
                $attrs[$key] = max(0, $share);
                $allocated  += $attrs[$key];
            }
        }

        return $attrs;
    }

    /**
     * Generate 1–2 random coaching specialisms.
     * 40% chance single specialism, 60% chance dual.
     * Values 50–90; keys drawn from ATTRIBUTE_KEYS.
     */
    private function generateSpecialisms(): array
    {
        $keys  = self::ATTRIBUTE_KEYS;
        shuffle($keys);
        $count = random_int(1, 100) <= 40 ? 1 : 2;
        $specialisms = [];
        foreach (array_slice($keys, 0, $count) as $key) {
            $specialisms[$key] = random_int(50, 90);
        }
        return $specialisms;
    }

    /** Return preferred size 80 % of the time; random otherwise */
    private function sizeWithVariance(CompanySize $preferred): CompanySize
    {
        if (random_int(1, 100) <= 80) {
            return $preferred;
        }
        $cases = CompanySize::cases();
        return $cases[array_rand($cases)];
    }
}

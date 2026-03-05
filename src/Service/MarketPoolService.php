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

    // Name pools
    private const FIRST_NAMES = [
        'Luca', 'Noah', 'Mateo', 'Elias', 'Omar', 'Ibrahim', 'Karim', 'Yusuf',
        'Thiago', 'Gabriel', 'Samuel', 'Daniel', 'Leo', 'Felix', 'Emil',
        'Axel', 'Noa', 'Kian', 'Tariq', 'Amadou', 'Seun', 'Kwame', 'Taye',
        'Soren', 'Finn', 'Erik', 'Tobias', 'Adrian', 'Julian', 'Oscar',
        'Cristian', 'Remy', 'Enzo', 'Vitor', 'Bruno', 'Edu', 'Nico', 'Max',
        'Jan', 'Kai', 'Zach', 'Tyler', 'Jordan', 'Marcus', 'Raheem', 'Callum',
        'Caden', 'Jayden', 'Isaiah', 'Kofi', 'Aidan', 'Ethan', 'Javier', 'Pablo',
    ];

    private const LAST_NAMES = [
        'Rossi', 'Bianchi', 'Ferrari', 'Conti', 'Esposito', 'Romano',
        'Silva', 'Santos', 'Oliveira', 'Ferreira', 'Costa', 'Carvalho',
        'García', 'Martínez', 'López', 'González', 'Rodríguez',
        'Müller', 'Schmidt', 'Fischer', 'Weber', 'Meyer', 'Hoffmann',
        'Dupont', 'Dubois', 'Bernard', 'Moreau', 'Laurent', 'Simon',
        'Smith', 'Jones', 'Williams', 'Taylor', 'Brown', 'Davies',
        'Diallo', 'Camara', 'Traoré', 'Koné', 'Coulibaly', 'Touré',
        'De Jong', 'Van Dijk', 'Bakker', 'Janssen', 'Christensen',
        'Andersen', 'Nielsen', 'Hansen', 'Pedersen', 'Larsson', 'Nilsson',
    ];

    private const STAFF_FIRST_NAMES = [
        'Roberto', 'Marco', 'Fabio', 'Luca', 'Giovanni', 'Antonio',
        'Carlos', 'Luis', 'Javier', 'Pedro', 'Alejandro', 'Fernando',
        'Thomas', 'Michael', 'Stefan', 'Andreas', 'Markus', 'Ralf',
        'Patrick', 'Nicolas', 'Sébastien', 'Laurent', 'Thierry',
        'Gary', 'Steve', 'Mark', 'Paul', 'Chris', 'Andrew', 'James',
        'Nuno', 'Rui', 'Filipe', 'Tiago', 'André',
    ];

    private const STAFF_LAST_NAMES = [
        'Conte', 'Mancini', 'Capello', 'Ancelotti', 'Allegri',
        'Scolari', 'Tite', 'Zagallo', 'Parreira',
        'Simeone', 'Valverde', 'Marcelino', 'Lopetegui',
        'Flick', 'Nagelsmann', 'Tuchel', 'Klopp', 'Rangnick',
        'Deschamps', 'Blanc', 'Jacquet', 'Domenech',
        'Hodgson', 'Southgate', 'McLaren', 'Robson',
        'Fonseca', 'Conceição', 'Villas-Boas',
    ];

    private const AGENT_NAMES = [
        'Jorge Mendes', 'Jonathan Barnett', 'Pini Zahavi', 'Kia Joorabchian',
        'Fernando Felicevich', 'Volker Struth', 'Pere Guardiola',
        'Frederic Massara', 'Giovanni Branchini', 'Atta Aneke',
        'John Shier', 'David Manasseh', 'Rob Segal', 'Nick Arcuri',
        'Marc Roger', 'Christophe Henrotay', 'Saif Rubie', 'Sky Andrew',
        'Global Sports Agency', 'Stellar Group', 'CAA Sports', 'Wasserman Media',
        'Base Soccer Agency', 'ICM Stellar Sports', 'Octagon Sports',
    ];

    private const SCOUT_NAMES = [
        'Carlos Silva', 'Luis Santos', 'Miguel Rodriguez', 'Antonio Garcia',
        'Marco Rossi', 'Lars Müller', 'Hans Schmidt', 'Pierre Dubois',
        'John Smith', 'David Johnson', 'Alex Meyer', 'Viktor Fischer',
        'Rafael Costa', 'Andre Ferreira', 'Diego Pereira', 'Sergio Lopez',
        'Ivan Novak', 'Leon Wagner', 'Stefan Hoffmann', 'Tomasz Kowalski',
    ];

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

    private const NATIONALITIES = [
        'Brazilian', 'Argentine', 'Spanish', 'Portuguese', 'Italian',
        'German', 'French', 'English', 'Dutch', 'Belgian',
        'Croatian', 'Uruguayan', 'Mexican', 'Colombian', 'American',
        'Japanese', 'South Korean', 'Turkish', 'Danish', 'Swedish',
    ];

    private const PRIME_NATIONALITIES = [
        'English', 'Spanish', 'Brazilian', 'French', 'German',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlayerRepository       $playerRepo,
        private readonly StaffRepository        $staffRepo,
        private readonly ScoutRepository        $scoutRepo,
        private readonly AgentRepository        $agentRepo,
        private readonly SponsorRepository      $sponsorRepo,
        private readonly InvestorRepository     $investorRepo,
    ) {}

    // -------------------------------------------------------------------------
    // Generate
    // -------------------------------------------------------------------------

    /** @return Player[] */
    public function generatePlayers(int $count, ?int $academyReputation = null): array
    {
        $agents      = $this->agentRepo->findAll();
        $multipliers = $this->getWageMultiplier($academyReputation);
        $players     = [];

        for ($i = 0; $i < $count; $i++) {
            $potential      = $this->bellCurveInt(40, 80, 60);
            $currentAbility = max(20, $potential - random_int(10, 20));
            $age            = random_int(13, 14);

            $player = new Player(
                firstName:         $this->pick(self::FIRST_NAMES),
                lastName:          $this->pick(self::LAST_NAMES),
                dateOfBirth:       $this->dobFromAge($age),
                nationality:       $this->weightedNationality(),
                position:          $this->weightedPosition(),
                recruitmentSource: RecruitmentSource::YOUTH_INTAKE,
                potential:         $potential,
                currentAbility:    $currentAbility,
                academy:           null,
            );

            $player->setStatus(PlayerStatus::ACTIVE);
            $baseWage = $currentAbility * random_int(10, 40);
            $player->setContractValue((int) ($baseWage * $multipliers['player']));

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

            $staff = new Staff(
                firstName: $this->pick(self::STAFF_FIRST_NAMES),
                lastName:  $this->pick(self::STAFF_LAST_NAMES),
                role:      $role,
                academy:   null,
            );

            $staff->setCoachingAbility($ability);
            $staff->setScoutingRange(random_int(40, 75));

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
            $age        = random_int(28, 40);
            $experience = random_int(0, 10);
            $name       = self::SCOUT_NAMES[$i % count(self::SCOUT_NAMES)]
                . ($i >= count(self::SCOUT_NAMES) ? ' ' . ($i + 1) : '');

            $scout = new Scout($name);
            $scout->setDob($this->dobFromAge($age));
            $scout->setNationality($this->pick(self::NATIONALITIES));
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
        $names  = $this->pickUnique(self::AGENT_NAMES, $count);

        for ($i = 0; $i < $count; $i++) {
            $reputation = random_int(30, 70);
            $rating     = max(1, min(100, $reputation + random_int(-10, 10)));
            $experience = max(5, $reputation - random_int(5, 15));
            $age        = random_int(30, 60);

            $commissionBase = (int) (800 + ($reputation / 100) * 700);
            $commissionRate = number_format(random_int($commissionBase, $commissionBase + 400) / 100, 2);

            $agent = new Agent($names[$i]);
            $agent->setReputation($reputation);
            $agent->setRating($rating);
            $agent->setExperience($experience);
            $agent->setCommissionRate($commissionRate);
            $agent->setDob($this->dobFromAge($age));
            $agent->setNationality($this->pick(self::NATIONALITIES));
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
            $sponsor->setNationality($this->pick(self::NATIONALITIES));
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
            $investor->setNationality($this->pick(self::NATIONALITIES));
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

    /** @return Player[] */
    public function getAvailablePlayers(int $limit = 100): array
    {
        return $this->playerRepo->findInPool($limit);
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

    /** 70 % prime nationalities, 30 % diverse */
    private function weightedNationality(): string
    {
        return random_int(1, 100) <= 70
            ? $this->pick(self::PRIME_NATIONALITIES)
            : $this->pick(self::NATIONALITIES);
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

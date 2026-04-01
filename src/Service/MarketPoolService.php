<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Academy;
use App\Entity\Agent;
use App\Entity\Guardian;
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
use App\Entity\PoolConfig;
use App\Repository\AgentRepository;
use App\Repository\InvestorRepository;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\SponsorRepository;
use App\Repository\StaffRepository;
use Doctrine\ORM\EntityManagerInterface;

class MarketPoolService
{
    // ── Nationality → regional cluster ──────────────────────────────────────
    // Used to add regional flavour to SMALL and MEDIUM business names.
    private const NATIONALITY_MAP = [
        'English'      => 'british_isles',
        'Irish'        => 'british_isles',
        'German'       => 'central_europe',
        'Dutch'        => 'central_europe',
        'Polish'       => 'eastern_europe',
        'French'       => 'western_europe',
        'Italian'      => 'southern_europe',
        'Spanish'      => 'southern_europe',
        'Portuguese'   => 'southern_europe',
        'Brazilian'    => 'south_america',
        'Argentine'    => 'south_america',
        'Swedish'      => 'scandinavia',
        'Danish'       => 'scandinavia',
        'Irish'        => 'british_isles',
        'Senegalese'   => 'west_africa',
        'Nigerian'     => 'west_africa',
        'Ghanaian'     => 'west_africa',
        'Ivorian'      => 'west_africa',
        'Japanese'     => 'east_asia',
        'South Korean' => 'east_asia',
        'Chinese'      => 'east_asia',
    ];

    // ── Business cluster location words (MEDIUM business names) ─────────────
    private const BUSINESS_CLUSTERS = [
        'british_isles'  => ['Northern', 'Yorkshire', 'Midlands', 'Westfield', 'Broadshire', 'Crestwood', 'Pennine', 'Lakeland'],
        'central_europe' => ['Rhine', 'Alpine', 'Westmark', 'Nordhaven', 'Mittelfeld', 'Silesian', 'Bavarian', 'Thuringian'],
        'eastern_europe' => ['Vistula', 'Amber', 'Baltica', 'Carpathian', 'Masovian', 'Silesian', 'Pomeranian', 'Podolian'],
        'western_europe' => ['Normandy', 'Atlantic', 'Vendôme', 'Breton', 'Burgundy', 'Aquitaine', 'Provençal', 'Alsatian'],
        'southern_europe' => ['Mediterranean', 'Adriatic', 'Iberian', 'Tuscan', 'Andaluz', 'Ligurian', 'Sardinian', 'Valencian'],
        'south_america'  => ['Pampas', 'Andean', 'Cerrado', 'Plata', 'Gaucho', 'Patagonian', 'Rioplatense', 'Amazonian'],
        'scandinavia'    => ['Nordic', 'Viking', 'Baltic', 'Fjord', 'Boreal', 'Midnight', 'Lapland', 'Archipelago'],
        'west_africa'    => ['Sahel', 'Coastal', 'Savanna', 'Harmattan', 'Gulf', 'Tropical', 'Atlantic', 'Lagoon'],
        'east_asia'      => ['Pacific', 'Sunrise', 'Tokai', 'Hokkai', 'Yangtze', 'Pearl River', 'Mekong', 'Eastern'],
        '_fallback'      => ['Global', 'International', 'Central', 'Northern', 'Eastern', 'Western', 'Continental', 'Universal'],
    ];

    // ── SMALL business name components ───────────────────────────────────────
    private const SMALL_GIVEN_NAMES = [
        'Dave', 'Ricky', 'Terry', 'Gary', 'Mick', 'Pete', 'Tony', 'Billy', 'Bobby',
        'Ian', 'Steve', 'Andy', 'Kev', 'Stu', 'Daz', 'Baz', 'Mo', 'Sal', 'Reg', 'Lenny',
        'Vince', 'Ray', 'Roy', 'Sid', 'Clive', 'Barry', 'Des', 'Neil', 'Alan', 'Phil',
        'Geoff', 'Cliff', 'Den', 'Vic', 'Len', 'Alf', 'Ron', 'Stan', 'Bert', 'Walt',
    ];

    private const SMALL_TRADES = [
        'Plastering', 'Roofing', 'Motors', 'Bakery', 'Butchers', 'Hardware', 'Electrics',
        'Plumbing', 'Carpentry', 'Fencing', 'Groundworks', 'Landscaping', 'Tyres', 'Autos',
        'Print Shop', 'Removals', 'Cleaning', 'Security', 'Catering', 'Flooring',
        'Decorating', 'Signage', 'Welding', 'Drainage', 'Pest Control', 'Locksmithing',
        'Upholstery', 'Glazing', 'Tiling', 'Scaffolding', 'Tree Surgery',
    ];

    private const SMALL_ADJECTIVES = [
        'Village', 'Local', 'Family', 'County', 'High Street', 'Corner', 'Market', 'Friendly',
        'Community', 'Trusty', 'Traditional', 'Heritage', 'Classic', 'Reliable', 'Old Town',
        'Neighbourhood', 'Independent', 'Honest', 'Homegrown', 'Artisan',
    ];

    private const SMALL_PLACE_NOUNS = [
        'Bakery', 'Cafe', 'Garage', 'Workshop', 'Kitchen', 'Diner', 'Stores', 'Services',
        'Supplies', 'Centre', 'Shop', 'Traders', 'Works', 'Yard',
    ];

    // ── MEDIUM business name components ──────────────────────────────────────
    private const MEDIUM_INDUSTRIES = [
        'Logistics', 'Engineering', 'Manufacturing', 'Technology', 'Construction', 'Media',
        'Finance', 'Solutions', 'Consultancy', 'Services', 'Supplies', 'Distribution',
        'Development', 'Recruitment', 'Communications', 'Transport', 'Energy',
        'Healthcare', 'Education', 'Marketing', 'Analytics', 'Procurement', 'Trading',
    ];

    private const MEDIUM_QUALIFIERS = ['Group', 'Ltd', 'Co.', 'Partners', 'Associates', 'Alliance'];

    // ── LARGE (corporate) business name components ────────────────────────────
    private const LARGE_PREFIXES = [
        'Omni', 'Apex', 'Velt', 'Nex', 'Quant', 'Syn', 'Kor', 'Striv', 'Alt', 'Prox',
        'Zenith', 'Axiom', 'Verex', 'Kalis', 'Novus', 'Priva', 'Solus', 'Auro', 'Crest',
        'Davan', 'Elara', 'Forta', 'Helix', 'Inver', 'Karya', 'Macro', 'Norva', 'Orbis',
        'Paragon', 'Quorum', 'Strix', 'Talvex', 'Umbra', 'Vantage', 'Wyrd', 'Xeno',
        'Yotta', 'Zephyr', 'Castor', 'Draken', 'Evren',
    ];

    private const LARGE_COINED_SUFFIXES = [
        'Corp', 'io', 'ra', 'ex', 'ive', 'us', 'a', 'ix', 'on', 'al', 'yx', 'en', 'ar',
    ];

    private const LARGE_CORP_TERMS = [
        'Group', 'Capital', 'Holdings', 'Partners', 'Ventures', 'Global', 'International',
        'Alliance', 'Network', 'Industries', 'Dynamics', 'Systems', 'Collective', 'Advisors',
    ];

    // ── Player attribute configuration ───────────────────────────────────────
    private const ATTRIBUTE_KEYS = ['pace', 'technical', 'vision', 'power', 'stamina', 'heart'];

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
        private readonly PoolConfigRepository   $poolConfigRepo,
    ) {}

    // ── Generate ─────────────────────────────────────────────────────────────

    /** @return Player[] */
    public function generatePlayers(int $count, ?int $academyReputation = null, RecruitmentSource $source = RecruitmentSource::YOUTH_INTAKE, ?string $nationality = null): array
    {
        $cfg         = $this->poolConfigRepo->getConfig();
        $agents      = $this->agentRepo->findAll();
        $multipliers = $this->getWageMultiplier($academyReputation);
        $players     = [];

        for ($i = 0; $i < $count; $i++) {
            $potential      = $this->bellCurveInt($cfg->getPlayerPotentialMin(), $cfg->getPlayerPotentialMax(), $cfg->getPlayerPotentialMean());
            $currentAbility = random_int($cfg->getPlayerAbilityMin(), $cfg->getPlayerAbilityMax());
            $age            = random_int($cfg->getPlayerAgeMin(), $cfg->getPlayerAgeMax());
            $nat            = $nationality ?? $this->nameGenerator->getRandomNationality();

            // Complex name generation: mononyms, double-surnames, generational suffixes
            ['firstName' => $firstName, 'lastName' => $lastName] = $this->nameGenerator->generatePlayerName($nat);

            $player = new Player(
                firstName:         $firstName,
                lastName:          $lastName,
                dateOfBirth:       $this->dobFromAge($age),
                nationality:       $nat,
                position:          $this->weightedPosition($cfg),
                recruitmentSource: $source,
                potential:         $potential,
                currentAbility:    $currentAbility,
                academy:           null,
            );

            $player->setStatus(PlayerStatus::ACTIVE);
            $baseWage = $currentAbility * random_int(10, 40);
            $player->setContractValue((int) ($baseWage * $multipliers['player']));

            $attrBudget = random_int($cfg->getPlayerAttributeBudgetMin(), $cfg->getPlayerAttributeBudgetMax());
            $attrs      = $this->distributeAttributes($player->getPosition(), $attrBudget);
            $player->setPace($attrs['pace']);
            $player->setTechnical($attrs['technical']);
            $player->setVision($attrs['vision']);
            $player->setPower($attrs['power']);
            $player->setStamina($attrs['stamina']);
            $player->setHeart($attrs['heart']);

            $player->setHeight(random_int($cfg->getPlayerHeightMin(), $cfg->getPlayerHeightMax()));
            $player->setWeight(random_int($cfg->getPlayerWeightMin(), $cfg->getPlayerWeightMax()));

            if (!empty($agents) && random_int(1, 100) <= $cfg->getPlayerAgentChancePercent()) {
                $player->setAgent($agents[array_rand($agents)]);
            }

            $pMin = $cfg->getPersonalityTraitMin();
            $pMax = $cfg->getPersonalityTraitMax();
            $p    = $player->getPersonality();
            $p->setConfidence(random_int($pMin, $pMax));
            $p->setMaturity(random_int($pMin, $pMax));
            $p->setTeamwork(random_int($pMin, $pMax));
            $p->setLeadership(random_int($pMin, $pMax));
            $p->setEgo(random_int($pMin, $pMax));
            $p->setBravery(random_int($pMin, $pMax));
            $p->setGreed(random_int($pMin, $pMax));
            $p->setLoyalty(random_int($pMin, $pMax));

            // Guardian generation:
            // 80% → two parents, male + female
            // 10% → one parent, random gender
            // 10% → two parents, same gender (both male or both female)
            $roll = random_int(1, 100);
            if ($roll <= 80) {
                $genderPair = ['male', 'female'];
            } elseif ($roll <= 90) {
                $genderPair = [random_int(0, 1) === 0 ? 'male' : 'female'];
            } else {
                $sameGender = random_int(0, 1) === 0 ? 'male' : 'female';
                $genderPair = [$sameGender, $sameGender];
            }

            // Guardians inherit the player's family name. For mononym players (lastName = ''),
            // generate an independent last name from the same nationality pool.
            $guardianLastName = $lastName !== ''
                ? $lastName
                : $this->nameGenerator->generateLastName($nat);

            foreach ($genderPair as $guardianGender) {
                $guardianFirstName = $this->nameGenerator->generateFirstName($nat);
                $guardian          = new Guardian($guardianFirstName, $guardianLastName, $player, $guardianGender);
                $guardian->setDateOfBirth($this->dobFromAge(random_int(30, 55)));
                $guardian->setDemandLevel(random_int(1, 10));
                $guardian->setLoyaltyToAcademy(random_int(30, 80));
                $this->em->persist($guardian);
            }

            $this->em->persist($player);
            $players[] = $player;

            if ($i > 0 && $i % 50 === 0) {
                $this->em->flush();
                $this->em->clear(Player::class);
                $agents = $this->agentRepo->findAll();
            }
        }

        $this->em->flush();
        return $players;
    }

    /** @return Staff[] */
    public function generateCoaches(int $count, ?int $academyReputation = null): array
    {
        $cfg        = $this->poolConfigRepo->getConfig();
        $coachRoles = [
            StaffRole::HEAD_COACH,
            StaffRole::ASSISTANT_COACH,
            StaffRole::FITNESS_COACH,
            StaffRole::ANALYST,
        ];

        $multipliers = $this->getWageMultiplier($academyReputation);
        $coaches     = [];

        for ($i = 0; $i < $count; $i++) {
            $role      = $coachRoles[array_rand($coachRoles)];
            $ability   = random_int($cfg->getCoachAbilityMin(), $cfg->getCoachAbilityMax());
            $coachNat  = $this->nameGenerator->getRandomNationality();
            $coachName = $this->nameGenerator->generateName($coachNat);
            [$coachFirst, $coachLast] = array_pad(explode(' ', $coachName, 2), 2, '');

            $staff = new Staff(
                firstName: $coachFirst,
                lastName:  $coachLast,
                role:      $role,
                academy:   null,
            );

            $staff->setNationality($coachNat);
            $staff->setDob($this->dobFromAge(random_int($cfg->getCoachAgeMin(), $cfg->getCoachAgeMax())));
            $staff->setCoachingAbility($ability);
            $staff->setScoutingRange(random_int($cfg->getCoachAbilityMin(), $cfg->getCoachAbilityMax()));
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
        $cfg    = $this->poolConfigRepo->getConfig();
        $scouts = [];

        for ($i = 0; $i < $count; $i++) {
            $age        = random_int($cfg->getScoutAgeMin(), $cfg->getScoutAgeMax());
            $experience = random_int($cfg->getScoutExperienceMin(), $cfg->getScoutExperienceMax());
            $scoutNat   = $this->nameGenerator->getRandomNationality();
            $scoutName  = $this->nameGenerator->generateName($scoutNat);

            $scout = new Scout($scoutName);
            $scout->setDob($this->dobFromAge($age));
            $scout->setNationality($scoutNat);
            $scout->setExperience($experience);
            $scout->setJudgements([
                'potential'   => random_int($cfg->getScoutJudgementMin(), $cfg->getScoutJudgementMax()),
                'technical'   => random_int($cfg->getScoutJudgementMin(), $cfg->getScoutJudgementMax()),
                'physical'    => random_int($cfg->getScoutJudgementMin(), $cfg->getScoutJudgementMax()),
                'mental'      => random_int($cfg->getScoutJudgementMin(), $cfg->getScoutJudgementMax()),
                'personality' => random_int($cfg->getScoutJudgementMin(), $cfg->getScoutJudgementMax()),
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
        $cfg    = $this->poolConfigRepo->getConfig();
        $agents = [];

        for ($i = 0; $i < $count; $i++) {
            $reputation = random_int($cfg->getAgentReputationMin(), $cfg->getAgentReputationMax());
            $rating     = max(1, min(100, $reputation + random_int(-10, 10)));
            $experience = max(5, $reputation - random_int(5, 15));
            $age        = random_int($cfg->getAgentAgeMin(), $cfg->getAgentAgeMax());
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
    public function generateSponsors(int $count): array
    {
        $sponsors = [];

        for ($i = 0; $i < $count; $i++) {
            $nationality = $this->nameGenerator->getRandomNationality();
            $size        = $this->weightedCompanySize();
            $name        = $this->generateCompanyName($size, $nationality);

            $sponsor = new Sponsor($name);
            $sponsor->setNationality($nationality);
            $sponsor->setSize($size);
            $sponsor->setIsActive(true);

            $this->em->persist($sponsor);
            $sponsors[] = $sponsor;
        }

        $this->em->flush();
        return $sponsors;
    }

    /** @return Investor[] */
    public function generateInvestors(int $count): array
    {
        $investors = [];

        for ($i = 0; $i < $count; $i++) {
            $nationality = $this->nameGenerator->getRandomNationality();
            $size        = $this->weightedCompanySize();
            $name        = $this->generateCompanyName($size, $nationality);

            $investor = new Investor($name);
            $investor->setNationality($nationality);
            $investor->setSize($size);
            $investor->setIsActive(true);

            $this->em->persist($investor);
            $investors[] = $investor;
        }

        $this->em->flush();
        return $investors;
    }

    // ── Fetch ─────────────────────────────────────────────────────────────────

    /** @return Player[] Unassigned YOUTH_INTAKE players for the open market */
    public function getAvailablePlayers(int $limit = 100, ?string $nationality = null, ?int $abilityMin = null, ?int $abilityMax = null): array
    {
        return $this->playerRepo->findInPool($limit, $nationality, $abilityMin, $abilityMax);
    }

    /** @return Player[] Unassigned SCOUTING_NETWORK players for the scout prospect pool */
    public function getAvailableProspects(int $limit = 150): array
    {
        return $this->playerRepo->findProspects($limit);
    }

    /** @return Staff[] */
    public function getAvailableCoaches(int $limit = 20, ?int $abilityMin = null, ?int $abilityMax = null): array
    {
        return $this->staffRepo->findInPool(null, $limit, $abilityMin, $abilityMax);
    }

    /** @return Scout[] */
    public function getAvailableScouts(int $limit = 10, ?int $experienceMin = null, ?int $experienceMax = null): array
    {
        return $this->scoutRepo->findInPool($limit, $experienceMin, $experienceMax);
    }

    /** @return Agent[] */
    public function getAgents(int $limit = 20, ?int $ratingMin = null, ?int $ratingMax = null): array
    {
        return $this->agentRepo->findInPool($limit, $ratingMin, $ratingMax);
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

    // ── Assign ────────────────────────────────────────────────────────────────

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

    // ── Replenishment ─────────────────────────────────────────────────────────

    /**
     * Fills each pool up to its configured target.
     * Only generates entities for pools currently below their target.
     *
     * @return string[] Human-readable summary of what was generated.
     */
    public function replenishPool(): array
    {
        $cfg       = $this->poolConfigRepo->getConfig();
        $generated = [];

        if ($this->playerRepo->countInPool() < $cfg->getPlayerPoolTarget()) {
            $this->generatePlayers($cfg->getPlayerPoolTarget());
            $generated[] = $cfg->getPlayerPoolTarget() . ' players';
        }

        if ($this->staffRepo->countInPool() < $cfg->getCoachPoolTarget()) {
            $this->generateCoaches($cfg->getCoachPoolTarget());
            $generated[] = $cfg->getCoachPoolTarget() . ' coaches';
        }

        if ($this->scoutRepo->count([]) < $cfg->getScoutPoolTarget()) {
            $this->generateScouts($cfg->getScoutPoolTarget());
            $generated[] = $cfg->getScoutPoolTarget() . ' scouts';
        }

        if ($this->sponsorRepo->countInPool() < $cfg->getSponsorPoolTarget()) {
            $this->generateSponsors($cfg->getSponsorPoolTarget());
            $generated[] = $cfg->getSponsorPoolTarget() . ' sponsors';
        }

        if ($this->investorRepo->countInPool() < $cfg->getInvestorPoolTarget()) {
            $this->generateInvestors($cfg->getInvestorPoolTarget());
            $generated[] = $cfg->getInvestorPoolTarget() . ' investors';
        }

        if ($this->agentRepo->count([]) < $cfg->getAgentPoolTarget()) {
            $this->generateAgents($cfg->getAgentPoolTarget());
            $generated[] = $cfg->getAgentPoolTarget() . ' agents';
        }

        return $generated;
    }

    /**
     * Unconditionally generates the target batch count for each entity type,
     * regardless of current pool size.
     *
     * @return string[] Human-readable summary of what was generated.
     */
    public function forceGeneratePool(): array
    {
        $cfg       = $this->poolConfigRepo->getConfig();
        $generated = [];

        $this->generatePlayers($cfg->getPlayerPoolTarget());
        $generated[] = $cfg->getPlayerPoolTarget() . ' players';

        $this->generateCoaches($cfg->getCoachPoolTarget());
        $generated[] = $cfg->getCoachPoolTarget() . ' coaches';

        $this->generateScouts($cfg->getScoutPoolTarget());
        $generated[] = $cfg->getScoutPoolTarget() . ' scouts';

        $this->generateSponsors($cfg->getSponsorPoolTarget());
        $generated[] = $cfg->getSponsorPoolTarget() . ' sponsors';

        $this->generateInvestors($cfg->getInvestorPoolTarget());
        $generated[] = $cfg->getInvestorPoolTarget() . ' investors';

        $this->generateAgents($cfg->getAgentPoolTarget());
        $generated[] = $cfg->getAgentPoolTarget() . ' agents';

        return $generated;
    }

    // ── Business name generation ──────────────────────────────────────────────

    private function generateCompanyName(CompanySize $size, string $nationality): string
    {
        return match ($size) {
            CompanySize::SMALL  => $this->generateSmallCompanyName(),
            CompanySize::MEDIUM => $this->generateMediumCompanyName($nationality),
            CompanySize::LARGE  => $this->generateLargeCompanyName(),
        };
    }

    /**
     * SMALL — local, personal, trade-focused.
     * Patterns: "[Name]'s [Trade]" | "The [Adj] [PlaceNoun]" | "[Adj] [Trade]"
     */
    private function generateSmallCompanyName(): string
    {
        return match (random_int(1, 3)) {
            1 => $this->pick(self::SMALL_GIVEN_NAMES) . "'s " . $this->pick(self::SMALL_TRADES),
            2 => 'The ' . $this->pick(self::SMALL_ADJECTIVES) . ' ' . $this->pick(self::SMALL_PLACE_NOUNS),
            3 => $this->pick(self::SMALL_ADJECTIVES) . ' ' . $this->pick(self::SMALL_TRADES),
        };
    }

    /**
     * MEDIUM — regional, professional.
     * Pattern: "[ClusterLocation] [Industry]" with an optional qualifier.
     */
    private function generateMediumCompanyName(string $nationality): string
    {
        $cluster   = self::NATIONALITY_MAP[$nationality] ?? '_fallback';
        $locations = self::BUSINESS_CLUSTERS[$cluster] ?? self::BUSINESS_CLUSTERS['_fallback'];
        $location  = $this->pick($locations);
        $industry  = $this->pick(self::MEDIUM_INDUSTRIES);

        if (random_int(1, 10) <= 3) {
            return "{$location} {$industry} " . $this->pick(self::MEDIUM_QUALIFIERS);
        }

        return "{$location} {$industry}";
    }

    /**
     * LARGE — abstract/corporate.
     * Patterns:
     *   1. Coined word:  [Prefix][Suffix]                (e.g., "OmniCorp", "Veltra")
     *   2. Named entity: [Prefix][Suffix] [CorpTerm]     (e.g., "Apexio Holdings")
     *   3. Phrase:       [Prefix] [CorpTerm]             (e.g., "Zenith Capital")
     */
    private function generateLargeCompanyName(): string
    {
        return match (random_int(1, 3)) {
            1 => $this->pick(self::LARGE_PREFIXES) . $this->pick(self::LARGE_COINED_SUFFIXES),
            2 => $this->pick(self::LARGE_PREFIXES) . $this->pick(self::LARGE_COINED_SUFFIXES) . ' ' . $this->pick(self::LARGE_CORP_TERMS),
            3 => $this->pick(self::LARGE_PREFIXES) . ' ' . $this->pick(self::LARGE_CORP_TERMS),
        };
    }

    /**
     * Size weighting: 70% Small · 20% Medium · 10% Large.
     */
    private function weightedCompanySize(): CompanySize
    {
        $r = random_int(1, 100);
        return match (true) {
            $r <= 70 => CompanySize::SMALL,
            $r <= 90 => CompanySize::MEDIUM,
            default  => CompanySize::LARGE,
        };
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array{player: float, staff: float}
     */
    private function getWageMultiplier(?int $academyReputation): array
    {
        if ($academyReputation === null) {
            return ['player' => 1.0, 'staff' => 1.0];
        }

        return match (true) {
            $academyReputation < 100 => ['player' => 0.5, 'staff' => 0.6],
            $academyReputation < 300 => ['player' => 1.0, 'staff' => 1.0],
            $academyReputation < 600 => ['player' => 2.5, 'staff' => 2.0],
            default                  => ['player' => 5.0, 'staff' => 4.0],
        };
    }

    private function pick(array $items): mixed
    {
        return $items[array_rand($items)];
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
        $a   = random_int($min, $max);
        $b   = random_int($min, $max);
        $raw = (int) round(($a + $b) / 2);
        return (int) round(($raw + $mean) / 2);
    }

    private function weightedPosition(PoolConfig $cfg): PlayerPosition
    {
        $total = $cfg->getPositionWeightGk() + $cfg->getPositionWeightDef()
               + $cfg->getPositionWeightMid() + $cfg->getPositionWeightAtt();
        $r   = random_int(1, max(1, $total));
        $gk  = $cfg->getPositionWeightGk();
        $def = $gk  + $cfg->getPositionWeightDef();
        $mid = $def + $cfg->getPositionWeightMid();
        return match (true) {
            $r <= $gk  => PlayerPosition::GOALKEEPER,
            $r <= $def => PlayerPosition::DEFENDER,
            $r <= $mid => PlayerPosition::MIDFIELDER,
            default    => PlayerPosition::ATTACKER,
        };
    }

    /**
     * Distribute a total attribute budget across the 6 attributes, weighted by position.
     *
     * @return array{pace: int, technical: int, vision: int, power: int, stamina: int, heart: int}
     */
    private function distributeAttributes(PlayerPosition $position, int $total): array
    {
        $posKey = $position->value;
        $ranges = self::POSITION_ATTRIBUTES[$posKey] ?? self::POSITION_ATTRIBUTES['MID'];

        $weights     = [];
        $totalWeight = 0.0;
        foreach (self::ATTRIBUTE_KEYS as $key) {
            $w             = ($ranges[$key][0] + $ranges[$key][1]) / 2.0;
            $weights[$key] = $w;
            $totalWeight  += $w;
        }

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
     * 40% chance single specialism, 60% chance dual. Values 50–90.
     */
    private function generateSpecialisms(): array
    {
        $keys = self::ATTRIBUTE_KEYS;
        shuffle($keys);
        $count       = random_int(1, 100) <= 40 ? 1 : 2;
        $specialisms = [];
        foreach (array_slice($keys, 0, $count) as $key) {
            $specialisms[$key] = random_int(50, 90);
        }
        return $specialisms;
    }
}

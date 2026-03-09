<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Academy;
use App\Entity\User;
use App\Enum\StaffRole;
use Doctrine\ORM\EntityManagerInterface;

class AcademyInitializationService
{
    private const STARTING_PLAYERS   = 10;
    private const STARTING_COACHES   = 2;
    private const STARTING_SCOUTS    = 1;
    private const STARTING_SPONSORS  = 1;
    private const STARTING_INVESTORS = 0;

    private const PA_FIRST_NAMES = [
        'Marcus', 'Daniel', 'James', 'Ryan', 'Michael',
        'Jordan', 'Liam', 'Ethan', 'Nathan', 'Kyle',
        'Sophia', 'Emma', 'Olivia', 'Ava', 'Isabella',
        'Mia', 'Charlotte', 'Amelia', 'Harper', 'Evelyn',
    ];

    private const PA_LAST_NAMES = [
        'Richards', 'Thompson', 'Johnson', 'Clarke', 'Edwards',
        'Wilson', 'Hughes', 'Davies', 'Morris', 'Bennett',
        'Campbell', 'Foster', 'Patel', 'Khan', 'Okafor',
        'Mensah', 'Asante', 'Diallo', 'Nkosi', 'Osei',
    ];

    public function __construct(
        private readonly MarketPoolService      $pool,
        private readonly FacilityService        $facilityService,
        private readonly EntityManagerInterface $em,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%app.academy_starting_balance%')]
        private readonly int $startingBalance = 500000,
    ) {}

    /**
     * Enrich an existing academy with starter entities from the market pool.
     *
     * Pulls entities from the pool; if not enough exist, new ones are generated
     * on-the-fly so the call never fails due to an empty pool.
     *
     * @throws \RuntimeException if the user already has an initialized academy (has players)
     */
    public function initializeAcademy(User $user, string $academyName): Academy
    {
        $academy = $user->getAcademy();

        if ($academy === null) {
            $academy = new Academy($academyName, $user);
            $academy->setBalance($this->startingBalance);
            $academy->setPaName($this->generatePaName());
            $academy->setManagerTemperament(rand(40, 60));
            $academy->setManagerDiscipline(rand(40, 60));
            $academy->setManagerAmbition(rand(40, 60));
            $this->em->persist($academy);
            $this->em->flush();
        }

        // Idempotency guard: already initialized if it has players
        if ($academy->getPlayers()->count() > 0) {
            throw new \RuntimeException('Academy is already initialized.');
        }

        $this->em->wrapInTransaction(function () use ($academy): void {
            $this->assignEntities($academy);
        });

        return $academy;
    }

    public function getStarterBundle(): array
    {
        return [
            'startingBalance'   => $this->startingBalance,
            'players'           => self::STARTING_PLAYERS,   // 10
            'coaches'           => self::STARTING_COACHES,   // 2
            'scouts'            => self::STARTING_SCOUTS,    // 1
            'sponsors'          => self::STARTING_SPONSORS,  // 0
            'investors'         => self::STARTING_INVESTORS, // 0
        ];
    }

    private function assignEntities(Academy $academy): void
    {
        // Players
        $players = $this->pool->getAvailablePlayers(self::STARTING_PLAYERS);
        if (count($players) < self::STARTING_PLAYERS) {
            $extra = $this->pool->generatePlayers(self::STARTING_PLAYERS - count($players));
            $players = array_merge($players, $extra);
        }
        foreach (array_slice($players, 0, self::STARTING_PLAYERS) as $player) {
            $player->setMorale(rand(60, 80));
            $this->pool->assignToAcademy($player, $academy);
        }

        // Coaches
        $coaches = $this->pool->getAvailableCoaches(self::STARTING_COACHES);
        if (count($coaches) < self::STARTING_COACHES) {
            $extra = $this->pool->generateCoaches(self::STARTING_COACHES - count($coaches));
            $coaches = array_merge($coaches, $extra);
        }
        $coachSpecialties = ['Technique', 'Physicality', 'Tactical', 'Mental'];
        foreach (array_slice($coaches, 0, self::STARTING_COACHES) as $coach) {
            $coach->setMorale(rand(70, 90));
            if (in_array($coach->getRole(), [StaffRole::HEAD_COACH, StaffRole::ASSISTANT_COACH], true)) {
                $coach->setSpecialty($coachSpecialties[array_rand($coachSpecialties)]);
            }
            $this->pool->assignToAcademy($coach, $academy);
        }

        // Scouts
        $scouts = $this->pool->getAvailableScouts(self::STARTING_SCOUTS);
        if (count($scouts) < self::STARTING_SCOUTS) {
            $this->pool->generateScouts(self::STARTING_SCOUTS - count($scouts));
            $scouts = $this->pool->getAvailableScouts(self::STARTING_SCOUTS);
        }
        foreach (array_slice($scouts, 0, self::STARTING_SCOUTS) as $scout) {
            $this->pool->assignToAcademy($scout, $academy);
        }

        // Sponsors
        $sponsors = $this->pool->getAvailableSponsorPool(self::STARTING_SPONSORS);
        if (count($sponsors) < self::STARTING_SPONSORS) {
            $extra = $this->pool->generateSponsors(self::STARTING_SPONSORS - count($sponsors));
            $sponsors = array_merge($sponsors, $extra);
        }
        foreach (array_slice($sponsors, 0, self::STARTING_SPONSORS) as $sponsor) {
            $this->pool->assignToAcademy($sponsor, $academy);
        }

        // Investors — 0 at start; academies earn investors through gameplay
        if (self::STARTING_INVESTORS > 0) {
            $investors = $this->pool->getAvailableInvestorPool(self::STARTING_INVESTORS);
            if (count($investors) < self::STARTING_INVESTORS) {
                $extra     = $this->pool->generateInvestors(self::STARTING_INVESTORS - count($investors));
                $investors = array_merge($investors, $extra);
            }
            foreach (array_slice($investors, 0, self::STARTING_INVESTORS) as $investor) {
                $this->pool->assignToAcademy($investor, $academy);
            }
        }

        // Facilities — one per type, all at level 0
        $this->facilityService->initializeFacilities($academy);
    }

    private function generatePaName(): string
    {
        $first = self::PA_FIRST_NAMES[array_rand(self::PA_FIRST_NAMES)];
        $last  = self::PA_LAST_NAMES[array_rand(self::PA_LAST_NAMES)];
        return "$first $last";
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Academy;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class AcademyInitializationService
{
    private const STARTING_PLAYERS   = 20;
    private const STARTING_COACHES   = 2;
    private const STARTING_SCOUTS    = 1;
    private const STARTING_SPONSORS  = 2;
    private const STARTING_INVESTORS = 1;

    public function __construct(
        private readonly MarketPoolService     $pool,
        private readonly EntityManagerInterface $em,
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
            'players'   => self::STARTING_PLAYERS,
            'coaches'   => self::STARTING_COACHES,
            'scouts'    => self::STARTING_SCOUTS,
            'sponsors'  => self::STARTING_SPONSORS,
            'investors' => self::STARTING_INVESTORS,
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
            $this->pool->assignToAcademy($player, $academy);
        }

        // Coaches
        $coaches = $this->pool->getAvailableCoaches(self::STARTING_COACHES);
        if (count($coaches) < self::STARTING_COACHES) {
            $extra = $this->pool->generateCoaches(self::STARTING_COACHES - count($coaches));
            $coaches = array_merge($coaches, $extra);
        }
        foreach (array_slice($coaches, 0, self::STARTING_COACHES) as $coach) {
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

        // Investors
        $investors = $this->pool->getAvailableInvestorPool(self::STARTING_INVESTORS);
        if (count($investors) < self::STARTING_INVESTORS) {
            $extra = $this->pool->generateInvestors(self::STARTING_INVESTORS - count($investors));
            $investors = array_merge($investors, $extra);
        }
        foreach (array_slice($investors, 0, self::STARTING_INVESTORS) as $investor) {
            $this->pool->assignToAcademy($investor, $academy);
        }
    }
}

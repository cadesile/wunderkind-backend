<?php

declare(strict_types=1);

namespace App\Service\Club;

use App\Entity\Club;
use App\Entity\Location\PlotClaim;
use App\Repository\ClubRepository;
use App\Repository\GameConfigRepository;
use App\Repository\Location\PlotClaimRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Game over: the club is liquidated, the OWNER KEEPS THE PLOT (Courtney, 2026-11-26).
 *
 * THE DEVICE DECIDES. With the balance below zero and fan morale below
 * `gameOverFanMoraleThreshold`, the app warns on Advance that the club will be liquidated; the
 * player confirms (or invests first). Both are device-owned state (finance rule: all financial
 * calculation happens on the device), so the server only records the outcome.
 *
 * WHAT LIQUIDATION DOES
 *   - stamps `club.liquidated_at` and `club.delete_after` (now + `liquidatedClubDeleteAfterDays`);
 *   - DETACHES every active plot claim the club stands on — not a release. The claim stays the
 *     owner's, so the plot is listed by `GET /api/plots/claims/mine` as restartable: the owner
 *     can found a new club on it (ClubInitializationService only accepts club-less claims) or
 *     sell it back, which then pays land + stadium only, since no club is attached;
 *   - clears `home_location`, so the town's name reservation is freed for the new club.
 *
 * No money moves. The club's debt dies with it; the owner's ledger is untouched.
 *
 * IDEMPOTENT. A second call keeps the first dates and finds nothing left to detach, so a retried
 * request after a lost response is harmless.
 *
 * The row is deleted later by app:clubs:purge-liquidated through ClubScrubService. By then it has
 * no active claim, so the scrub's release step finds nothing and the plot is safe.
 */
final class ClubLiquidationService
{
    public function __construct(
        private readonly PlotClaimRepository    $claimRepository,
        private readonly GameConfigRepository   $configRepository,
        private readonly ClubRepository         $clubRepository,
        private readonly ClubScrubService       $scrub,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * @return list<PlotClaim> the claims detached by THIS call (empty on a replay)
     */
    public function liquidate(Club $club, ?\DateTimeImmutable $now = null): array
    {
        $now  = $now ?? new \DateTimeImmutable();
        $days = $this->configRepository->getConfig()->getLiquidatedClubDeleteAfterDays();

        $club->markLiquidated($now, $now->modify(sprintf('+%d days', $days)));

        $claims = $this->claimRepository->findActiveForClub($club);
        foreach ($claims as $claim) {
            $claim->detachClub($now);
        }
        $club->setHomeLocation(null);

        $this->em->flush();

        $this->logger->info('Club liquidated', [
            'clubId'      => (string) $club->getId(),
            'clubName'    => $club->getName(),
            'claims'      => array_map(static fn (PlotClaim $c): string => (string) $c->getId(), $claims),
            'deleteAfter' => $club->getDeleteAfter()?->format(\DateTimeInterface::ATOM),
        ]);

        return $claims;
    }

    /**
     * Liquidated clubs whose `delete_after` has passed.
     *
     * @return list<Club>
     */
    public function findDue(\DateTimeImmutable $now, int $limit): array
    {
        return $this->clubRepository->createQueryBuilder('c')
            ->where('c.liquidatedAt IS NOT NULL')
            ->andWhere('c.deleteAfter <= :now')
            ->setParameter('now', $now)
            ->orderBy('c.deleteAfter', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Delete one due club. Detaches any claim first, in case one was attached after liquidation
     * by a support fix, so the scrub can never release (and so take) the owner's plot.
     *
     * @return array<string, int>
     */
    public function purge(Club $club): array
    {
        foreach ($this->claimRepository->findActiveForClub($club) as $claim) {
            $claim->detachClub();
        }
        $this->em->flush();

        return $this->scrub->scrub($club);
    }
}

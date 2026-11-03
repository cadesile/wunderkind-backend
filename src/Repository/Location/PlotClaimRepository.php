<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Club;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\Location\PlotClaim;
use App\Entity\User;
use App\Enum\Location\ClaimReleaseReason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\UuidV7;

/**
 * @extends ServiceEntityRepository<PlotClaim>
 *
 * The claim insert and the release are raw DBAL for the reason PlotHoldRepository gives: the
 * partial unique on (plot_id) WHERE released_at IS NULL is what makes "one club per plot" true,
 * and letting it throw out of flush() would close the shared EntityManager on a user-facing
 * write.
 */
class PlotClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlotClaim::class);
    }

    /**
     * Claim a plot, or return null if someone already holds it.
     *
     * ON CONFLICT DO NOTHING against uq_plot_claim_active_plot. Returns the new claim's id on
     * success so the caller can load it and attach the ledger rows in the same transaction.
     */
    public function insertIfUnclaimed(
        LandPlot $plot,
        User $user,
        int $landPencePaid,
        ?string $stadiumEstimateId,
        \DateTimeImmutable $now,
    ): ?string {
        $id = (string) new UuidV7();

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO plot_claim
                 (id, plot_id, location_id, user_id, land_pence_paid, stadium_pence_paid,
                  stadium_estimate_id, claimed_at, created_at, updated_at)
             VALUES
                 (:id, :plotId, :locationId, :userId, :landPence, 0, :estimateId, :now, :now, :now)
             ON CONFLICT DO NOTHING',
            [
                'id'         => $id,
                'plotId'     => (string) $plot->getId(),
                'locationId' => (string) $plot->getLocation()->getId(),
                'userId'     => (string) $user->getId(),
                'landPence'  => $landPencePaid,
                'estimateId' => $stadiumEstimateId,
                'now'        => $now,
            ],
            ['now' => 'datetime_immutable'],
        );

        return $affected === 1 ? $id : null;
    }

    public function findActiveForPlot(LandPlot $plot): ?PlotClaim
    {
        return $this->createQueryBuilder('c')
            ->where('c.plot = :plot')
            ->andWhere('c.releasedAt IS NULL')
            ->setParameter('plot', $plot->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Active claims across a page of plots — what the list endpoint reads for `claimedBy`.
     *
     * The batch method the house style calls for, so splitting ownership into its own table does
     * not trade a column read for an N+1 of the same size.
     *
     * @param list<string> $plotIds
     *
     * @return array<string, PlotClaim> keyed by plot id
     */
    public function findActiveByPlots(array $plotIds): array
    {
        if ($plotIds === []) {
            return [];
        }

        $claims = $this->createQueryBuilder('c')
            ->where('c.plot IN (:ids)')
            ->andWhere('c.releasedAt IS NULL')
            ->setParameter('ids', $plotIds)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($claims as $claim) {
            $out[(string) $claim->getPlot()->getId()] = $claim;
        }

        return $out;
    }

    /**
     * `claimedBy` names for a page of plots, without hydrating a claim at all.
     *
     * The projection the public list endpoint actually wants: it needs a string per plot, and
     * ORM 3 would decode every column of every claim to get it.
     *
     * @param list<string> $plotIds
     *
     * @return array<string, string> plot id => display name
     */
    public function findClaimedByNames(array $plotIds): array
    {
        if ($plotIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT pc.plot_id, COALESCE(pc.club_name_at_claim, c.name) AS name
               FROM plot_claim pc
               LEFT JOIN club c ON c.id = pc.club_id
              WHERE pc.plot_id IN (:ids)
                AND pc.released_at IS NULL',
            ['ids' => $plotIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $out = [];
        foreach ($rows as $row) {
            if ($row['name'] !== null) {
                $out[(string) $row['plot_id']] = (string) $row['name'];
            }
        }

        return $out;
    }

    /** @return list<PlotClaim> */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->andWhere('c.releasedAt IS NULL')
            ->setParameter('user', $user->getId())
            ->orderBy('c.claimedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<PlotClaim> */
    public function findActiveForClub(Club $club): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.club = :club')
            ->andWhere('c.releasedAt IS NULL')
            ->setParameter('club', $club->getId())
            ->getQuery()
            ->getResult();
    }

    public function countActiveInLocation(Location $location): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.location = :location')
            ->andWhere('c.releasedAt IS NULL')
            ->setParameter('location', $location->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * End a claim, conditionally.
     *
     * The conditional UPDATE IS the lock — the reclaimIfAbandoned() idiom. Two concurrent
     * sell-backs of the same plot cannot both succeed, because only the first finds
     * released_at IS NULL, and the caller checks the affected count rather than trusting a prior
     * read.
     */
    public function releaseIfActive(
        string $claimId,
        ClaimReleaseReason $reason,
        ?int $sellBackPence,
        \DateTimeImmutable $now,
    ): bool {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE plot_claim
                SET released_at = :now, release_reason = :reason, sell_back_pence = :sellBack, updated_at = :now
              WHERE id = :id AND released_at IS NULL',
            [
                'now'      => $now,
                'reason'   => $reason->value,
                'sellBack' => $sellBackPence,
                'id'       => $claimId,
            ],
            ['now' => 'datetime_immutable'],
        );
    }

    /**
     * Release every active claim held by any of these clubs, before the clubs are deleted.
     *
     * Required rather than optional. plot_claim.club_id is ON DELETE SET NULL so the purchase
     * record survives a club delete — but uq_plot_claim_active_plot keys on plot_id, so a
     * nulled-but-still-active claim would leave the plot owned by NOBODY and permanently
     * unbuyable. Releasing first is what prevents that.
     *
     * @param list<string> $clubIds
     *
     * @return list<string> the claim ids released, so the caller can pay out
     */
    public function releaseActiveForClubs(array $clubIds, \DateTimeImmutable $now): array
    {
        if ($clubIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'UPDATE plot_claim
                SET released_at = :now, release_reason = :reason, updated_at = :now
              WHERE club_id IN (:clubIds)
                AND released_at IS NULL
           RETURNING id',
            [
                'now'     => $now,
                'reason'  => ClaimReleaseReason::CLUB_DELETED->value,
                'clubIds' => $clubIds,
            ],
            ['now' => 'datetime_immutable', 'clubIds' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /**
     * Active claims whose club has been inactive since before the cutoff.
     *
     * Drives the dormancy warning and reclaim passes. Projected, not hydrated: the callers want
     * ids and a date, and these rows carry a valuation blob's worth of neighbours.
     *
     * @return list<array{claim_id: string, club_id: string, user_id: string, town: string, last_active_at: string}>
     */
    public function findDormantClaims(\DateTimeImmutable $inactiveSince, int $limit): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT pc.id AS claim_id,
                    pc.club_id,
                    pc.user_id,
                    l.town,
                    COALESCE(c.last_synced_at, c.created_at) AS last_active_at
               FROM plot_claim pc
               JOIN club c ON c.id = pc.club_id
               JOIN location l ON l.id = pc.location_id
              WHERE pc.released_at IS NULL
                AND COALESCE(c.last_synced_at, c.created_at) <= :cutoff
              ORDER BY COALESCE(c.last_synced_at, c.created_at) ASC
              LIMIT " . max(1, $limit),
            ['cutoff' => $inactiveSince],
            ['cutoff' => 'datetime_immutable'],
        );
    }
}

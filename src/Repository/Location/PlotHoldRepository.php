<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\LandPlot;
use App\Entity\Location\PlotHold;
use App\Entity\Location\StadiumEstimate;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\UuidV7;

/**
 * @extends ServiceEntityRepository<PlotHold>
 *
 * THE ACQUIRE PATH IS RAW DBAL, and that is the whole point of this class.
 *
 * Two players tapping HOLD PLOT at the same instant is the main race in this feature. The two
 * partial uniques on plot_hold settle it — but a UniqueConstraintViolationException out of
 * flush() CLOSES the shared EntityManager, and this runs on a user-facing write, so a losing
 * tap would take out the whole request including anything unrelated. `INSERT ... ON CONFLICT DO
 * NOTHING` plus a row count gives the same guarantee with no exception at all. Same reasoning as
 * LeaderboardPeriodCloseService::claim() and RewardApplierService::recordClaim().
 */
class PlotHoldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlotHold::class);
    }

    /**
     * Release any EXPIRED hold that would block an acquire for this plot or this user.
     *
     * Narrowed to exactly the two rows that could be in the way, rather than sweeping the table:
     * it runs on every acquire, and a table-wide update would make a hold attempt proportionally
     * expensive in the number of holds ever taken.
     *
     * This exists because a unique index predicate must be IMMUTABLE, so the partial uniques
     * cannot say `WHERE expires_at > now()` — an expired-but-unreleased hold therefore still
     * occupies the index. Sweeping here is what lets "expired holds are ignored, no cleanup job
     * needed" be true: the index is made to agree with the read semantics at the only moment it
     * matters. Idempotent, so any number of concurrent callers may run it.
     */
    public function releaseBlockingExpired(LandPlot $plot, User $user, \DateTimeImmutable $now): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE plot_hold
                SET released_at = :now
              WHERE released_at IS NULL
                AND consumed_at IS NULL
                AND expires_at <= :now
                AND (plot_id = :plotId OR user_id = :userId)',
            [
                'now'    => $now,
                'plotId' => (string) $plot->getId(),
                'userId' => (string) $user->getId(),
            ],
            ['now' => 'datetime_immutable'],
        );
    }

    /**
     * Insert a hold, or return null if the plot or the user already has a live one.
     *
     * Returns the new hold's id on success. ON CONFLICT DO NOTHING covers BOTH partial uniques,
     * so a caller cannot tell which one blocked them from the row count alone — the service asks
     * afterwards, when it needs to explain itself to the player.
     */
    public function insertIfFree(
        LandPlot $plot,
        User $user,
        int $landPence,
        StadiumEstimate $estimate,
        \DateTimeImmutable $now,
    ): ?string {
        $id = (string) new UuidV7();

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO plot_hold
                 (id, plot_id, user_id, land_pence, stadium_estimate_id, stadium_pence, created_at, expires_at)
             VALUES
                 (:id, :plotId, :userId, :landPence, :estimateId, :stadiumPence, :now, :expiresAt)
             ON CONFLICT DO NOTHING',
            [
                'id'           => $id,
                'plotId'       => (string) $plot->getId(),
                'userId'       => (string) $user->getId(),
                'landPence'    => $landPence,
                'estimateId'   => (string) $estimate->getId(),
                'stadiumPence' => $estimate->getPence(),
                'now'          => $now,
                'expiresAt'    => $now->modify('+' . PlotHold::HOLD_MINUTES . ' minutes'),
            ],
            ['now' => 'datetime_immutable', 'expiresAt' => 'datetime_immutable'],
        );

        return $affected === 1 ? $id : null;
    }

    /** The user's live hold, if any. Expiry is filtered here, not left to the index. */
    public function findActiveForUser(User $user, ?\DateTimeImmutable $now = null): ?PlotHold
    {
        return $this->activeQuery($now)
            ->andWhere('h.user = :user')
            ->setParameter('user', $user->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The plot's live hold, if any. */
    public function findActiveForPlot(LandPlot $plot, ?\DateTimeImmutable $now = null): ?PlotHold
    {
        return $this->activeQuery($now)
            ->andWhere('h.plot = :plot')
            ->setParameter('plot', $plot->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Live holds across a page of plots, for the list endpoint's "someone is looking at this".
     *
     * @param list<string> $plotIds
     *
     * @return array<string, PlotHold> keyed by plot id
     */
    public function findActiveByPlots(array $plotIds, ?\DateTimeImmutable $now = null): array
    {
        if ($plotIds === []) {
            return [];
        }

        $holds = $this->activeQuery($now)
            ->andWhere('h.plot IN (:ids)')
            ->setParameter('ids', $plotIds)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($holds as $hold) {
            $out[(string) $hold->getPlot()->getId()] = $hold;
        }

        return $out;
    }

    /**
     * Lock one hold FOR UPDATE and hand it back, inside the caller's transaction.
     *
     * The claim path's serialisation point: a double-tapped CLAIM has both requests reading the
     * same hold, and whichever gets the row lock first consumes it while the other then sees
     * consumed_at set. Raw, because the ORM has no pessimistic-lock-and-return-array idiom that
     * avoids hydrating.
     *
     * @return array<string, mixed>|null
     */
    public function lockForClaim(string $holdId): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT id, plot_id, user_id, land_pence, stadium_pence, stadium_estimate_id,
                    expires_at, consumed_at, released_at
               FROM plot_hold
              WHERE id = :id
                FOR UPDATE',
            ['id' => $holdId],
        );

        return $row === false ? null : $row;
    }

    /** Mark a hold spent. Conditional, so it cannot be consumed twice. */
    public function markConsumed(string $holdId, \DateTimeImmutable $now): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE plot_hold SET consumed_at = :now
              WHERE id = :id AND consumed_at IS NULL AND released_at IS NULL',
            ['now' => $now, 'id' => $holdId],
            ['now' => 'datetime_immutable'],
        );
    }

    /** Cancel a hold the player no longer wants. Conditional for the same reason. */
    public function markReleased(string $holdId, \DateTimeImmutable $now): bool
    {
        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE plot_hold SET released_at = :now
              WHERE id = :id AND released_at IS NULL AND consumed_at IS NULL',
            ['now' => $now, 'id' => $holdId],
            ['now' => 'datetime_immutable'],
        );
    }

    /**
     * Housekeeping sweep for every expired hold.
     *
     * NOT required for correctness — releaseBlockingExpired() handles the only case that affects
     * behaviour, and every read filters on expiry. This exists for admin hygiene and metrics, so
     * the table does not fill with rows that look live to someone reading it directly. Say so
     * loudly, or someone will assume holds leak without it.
     */
    public function releaseAllExpired(\DateTimeImmutable $now): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE plot_hold SET released_at = :now
              WHERE released_at IS NULL AND consumed_at IS NULL AND expires_at <= :now',
            ['now' => $now],
            ['now' => 'datetime_immutable'],
        );
    }

    private function activeQuery(?\DateTimeImmutable $now): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('h')
            ->where('h.releasedAt IS NULL')
            ->andWhere('h.consumedAt IS NULL')
            ->andWhere('h.expiresAt > :now')
            ->setParameter('now', $now ?? new \DateTimeImmutable());
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Club;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Entity\UserLedger;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserLedger>
 */
class UserLedgerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserLedger::class);
    }

    /**
     * The user's current overall centralized balance — the balanceAfterPence of their most
     * recently recorded entry, or 0 if they have none. Ordered by recordedAt then id (a
     * UuidV7 is itself time-ordered) so a tie on recordedAt still resolves deterministically.
     */
    public function getCurrentBalance(User $user): int
    {
        $result = $this->createQueryBuilder('l')
            ->select('l.balanceAfterPence')
            ->where('l.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.recordedAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null ? (int) $result['balanceAfterPence'] : 0;
    }

    /**
     * Total pence drawn against one specific club — distinct from getCurrentBalance(), which
     * is the user's overall balance across every club they own. Used by the admin user profile
     * panel to show "earnings drawn from this club" alongside the club's own totalCareerEarnings.
     */
    public function getTotalDividendsByClub(Club $club): int
    {
        return (int) ($this->createQueryBuilder('l')
            ->select('SUM(l.amountPence)')
            ->where('l.club = :club')
            ->setParameter('club', $club)
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
    }

    /** @return UserLedger[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.recordedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether an entry has already been recorded for this exact (sync record, ledger-array
     * index) pair — the idempotency check that makes re-running app:backfill-user-ledger, or
     * re-processing a resent sync, a safe no-op. Backs uq_user_ledger_source_entry.
     */
    public function existsForSource(SyncRecord $syncRecord, int $ledgerIndex): bool
    {
        $result = $this->createQueryBuilder('l')
            ->select('1')
            ->where('l.sourceSyncRecord = :syncRecord')
            ->andWhere('l.sourceLedgerIndex = :ledgerIndex')
            ->setParameter('syncRecord', $syncRecord)
            ->setParameter('ledgerIndex', $ledgerIndex)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null;
    }
}

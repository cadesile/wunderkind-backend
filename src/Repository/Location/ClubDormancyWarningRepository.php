<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\ClubDormancyWarning;
use App\Entity\Location\PlotClaim;
use App\Enum\Location\DormancyWarningStage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\UuidV7;

/**
 * @extends ServiceEntityRepository<ClubDormancyWarning>
 */
class ClubDormancyWarningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubDormancyWarning::class);
    }

    /**
     * Claim this (claim, stage, cycle) for a warning, or report that someone already has.
     *
     * RAW INSERT ... ON CONFLICT DO NOTHING, not a read-then-write. The dedupe has to be atomic
     * because two overlapping cron ticks would otherwise both read "no warning yet" and both
     * send — and because a UniqueConstraintViolationException out of flush() closes the shared
     * EntityManager, which on a batch pass would strand every remaining claim in the run. The
     * LeaderboardPeriodCloseService::claim() reasoning, verbatim.
     *
     * Returns the new row's id when this caller won, and null when a warning for this cycle
     * already exists. Only the winner should send.
     */
    public function claimWarning(
        PlotClaim $claim,
        DormancyWarningStage $stage,
        string $cycleKey,
        \DateTimeImmutable $now,
    ): ?string {
        $id = (string) new UuidV7();

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO club_dormancy_warning
                 (id, club_id, claim_id, stage, dormancy_cycle_key, created_at)
             VALUES (:id, :clubId, :claimId, :stage, :cycleKey, :now)
             ON CONFLICT (claim_id, stage, dormancy_cycle_key) DO NOTHING',
            [
                'id'       => $id,
                'clubId'   => (string) $claim->getClub()?->getId(),
                'claimId'  => (string) $claim->getId(),
                'stage'    => $stage->value,
                'cycleKey' => $cycleKey,
                'now'      => $now,
            ],
            ['now' => 'datetime_immutable'],
        );

        return $affected === 1 ? $id : null;
    }

    /**
     * Stamp a warning as delivered.
     *
     * Separate from claimWarning() on purpose — row first, then send, then stamp. See the
     * entity docblock on why that order and not the reverse.
     */
    public function markSent(string $warningId, ?string $inboxMessageId, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE club_dormancy_warning SET sent_at = :now, inbox_message_id = :msg WHERE id = :id',
            ['now' => $now, 'msg' => $inboxMessageId, 'id' => $warningId],
            ['now' => 'datetime_immutable'],
        );
    }

    /**
     * Which stages were actually DELIVERED for these claims in this cycle.
     *
     * FILTERS ON sent_at IS NOT NULL, and that is the whole point of the method. A row exists
     * from the moment claimWarning() wins the insert — i.e. BEFORE the message is sent, and it
     * stays there unstamped if the send failed. The reclaim pass asks "was this player actually
     * told?", so counting an unsent row would let a delivery failure cost somebody their plot
     * while the guard that exists to prevent exactly that reported success.
     *
     * Batch lookup so a reclaim pass can answer it for a whole page in one query.
     *
     * @param list<string> $claimIds
     *
     * @return array<string, list<string>> claim id => stage values delivered
     */
    public function sentStagesByClaim(array $claimIds, string $cycleKey): array
    {
        if ($claimIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT claim_id, stage FROM club_dormancy_warning
              WHERE claim_id IN (:ids)
                AND dormancy_cycle_key = :cycleKey
                AND sent_at IS NOT NULL',
            ['ids' => $claimIds, 'cycleKey' => $cycleKey],
            ['ids' => ArrayParameterType::STRING],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['claim_id']][] = (string) $row['stage'];
        }

        return $out;
    }
}

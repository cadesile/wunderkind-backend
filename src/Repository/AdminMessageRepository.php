<?php

namespace App\Repository;

use App\Entity\AdminMessage;
use App\Entity\Club;
use App\Enum\AudienceCriteriaType;
use App\Enum\MessageDeliveryStatus;
use App\Enum\MessageTargetType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminMessage>
 */
class AdminMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminMessage::class);
    }

    /**
     * Messages this club is potentially eligible for, in delivery order.
     *
     * "Potentially" because DYNAMIC audience groups pass this stage unconditionally — their
     * JSON criteria are not compiled into SQL. AdminMessageService filters them in PHP through
     * AudienceCriteriaEvaluator. The active-message count is small (tens), so the extra rows
     * cost nothing and the criteria stay readable.
     *
     * @return AdminMessage[]
     */
    public function findCandidatesForClub(Club $club, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable();
        $qb  = $this->createQueryBuilder('m');

        // Already shown or dismissed by this club's OWNER — this is what makes an ack stick.
        // Deliveries key on User, not Club, so starting a new club does not replay
        // announcements the player already dismissed.
        $acknowledged = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(\App\Entity\MessageDelivery::class, 'd')
            ->where('d.message = m')
            ->andWhere('d.user = :user')
            ->andWhere('d.status IN (:terminalStatuses)')
            ->getDQL();

        // Re-enter AdminMessage to traverse the ManyToMany; a plain JOIN on the outer query
        // would multiply rows and interact badly with the OR branches below.
        $targetedByGroup = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(AdminMessage::class, 'gm')
            ->join('gm.audienceGroups', 'g')
            ->leftJoin(
                \App\Entity\AudienceGroupMember::class,
                'mem',
                'WITH',
                'mem.group = g AND mem.club = :club',
            )
            ->where('gm = m')
            ->andWhere('g.criteriaType = :dynamic OR mem.id IS NOT NULL')
            ->getDQL();

        return $qb
            ->where('m.isActive = true')
            ->andWhere('m.validFrom <= :now')
            ->andWhere('m.validUntil IS NULL OR m.validUntil >= :now')
            ->andWhere($qb->expr()->not($qb->expr()->exists($acknowledged)))
            ->andWhere(
                $qb->expr()->orX(
                    'm.targetType = :broadcast',
                    $qb->expr()->andX('m.targetType = :direct', 'm.targetClub = :club'),
                    $qb->expr()->andX('m.targetType = :segmented', $qb->expr()->exists($targetedByGroup)),
                ),
            )
            ->setParameter('club', $club)
            ->setParameter('user', $club->getUser())
            ->setParameter('now', $now)
            ->setParameter('terminalStatuses', [
                MessageDeliveryStatus::DISPLAYED->value,
                MessageDeliveryStatus::DISMISSED->value,
            ])
            ->setParameter('dynamic', AudienceCriteriaType::DYNAMIC->value)
            ->setParameter('broadcast', MessageTargetType::BROADCAST->value)
            ->setParameter('direct', MessageTargetType::DIRECT_CLUB->value)
            ->setParameter('segmented', MessageTargetType::GROUP_SEGMENTED->value)
            ->orderBy('m.priority', 'DESC')
            ->addOrderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

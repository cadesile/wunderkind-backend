<?php

namespace App\Service;

use App\Repository\TransferRepository;
use Doctrine\ORM\EntityManagerInterface;

class TransferLeaderboardService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransferRepository $transferRepo,
    ) {}

    /**
     * Get top selling academies by total net proceeds for a period.
     *
     * @param string $period 'week' | 'month' | 'all-time'
     * @param int    $limit  1–50
     * @return array<array{academyName: string, totalSales: int, transferCount: int}>
     */
    public function getTopSellers(string $period = 'week', int $limit = 10): array
    {
        $qb = $this->transferRepo->createQueryBuilder('t')
            ->select('a.name as academyName')
            ->addSelect('SUM(t.netProceeds) as totalSales')
            ->addSelect('COUNT(t.id) as transferCount')
            ->join('t.academy', 'a')
            ->groupBy('a.id')
            ->orderBy('totalSales', 'DESC')
            ->setMaxResults(min($limit, 50));

        $this->applyPeriodFilter($qb, $period);

        return $qb->getQuery()->getResult();
    }

    /**
     * Get the single highest-value transfer for a period.
     *
     * @return array{playerName: string, academyName: string, netProceeds: int, buyingClub: string}|null
     */
    public function getMostValuableSale(string $period = 'week'): ?array
    {
        $qb = $this->transferRepo->createQueryBuilder('t')
            ->select('a.name as academyName', 't.netProceeds', 't.buyingClub')
            ->addSelect("CASE WHEN p.id IS NOT NULL THEN CONCAT(p.firstName, ' ', p.lastName) ELSE 'Unknown Player' END as playerName")
            ->join('t.academy', 'a')
            ->leftJoin('t.player', 'p')
            ->orderBy('t.netProceeds', 'DESC')
            ->setMaxResults(1);

        $this->applyPeriodFilter($qb, $period);

        $result = $qb->getQuery()->getOneOrNullResult();
        if (!$result) {
            return null;
        }

        return [
            'playerName'  => $result['playerName'],
            'academyName' => $result['academyName'],
            'netProceeds' => (int) $result['netProceeds'],
            'buyingClub'  => $result['buyingClub'],
        ];
    }

    private function applyPeriodFilter(\Doctrine\ORM\QueryBuilder $qb, string $period): void
    {
        $now = new \DateTimeImmutable();

        $start = match ($period) {
            'week'  => $now->modify('-7 days'),
            'month' => $now->modify('-30 days'),
            default => null,
        };

        if ($start !== null) {
            $qb->where('t.occurredAt >= :start')
               ->andWhere('t.occurredAt <= :end')
               ->setParameter('start', $start)
               ->setParameter('end', $now);
        }
    }
}

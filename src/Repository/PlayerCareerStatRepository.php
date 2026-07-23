<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\PlayerCareerStat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PlayerCareerStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerCareerStat::class);
    }

    public function findOrCreate(Club $club, string $playerId, string $playerName): PlayerCareerStat
    {
        $stat = $this->findOneBy([
            'club'     => $club,
            'playerId' => $playerId,
        ]);

        if ($stat === null) {
            $stat = new PlayerCareerStat($club, $playerId, $playerName);
            $this->getEntityManager()->persist($stat);
        }

        return $stat;
    }

    /**
     * Each club's top individual performer for the given stat column.
     * Returns one row per club: the row with the highest value of that column.
     *
     * @param 'goals'|'assists' $column
     * @return array<array{clubId: string, score: int, displayLabel: string}>
     */
    public function findTopPerformerByClub(string $column): array
    {
        if (!in_array($column, ['goals', 'assists'], true)) {
            throw new \InvalidArgumentException("Unsupported column: {$column}");
        }

        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.club) AS clubId', "s.{$column} AS score", 's.playerName AS displayLabel')
            ->orderBy("s.{$column}", 'DESC')
            ->getQuery()
            ->getArrayResult();

        $topPerClub = [];
        foreach ($rows as $row) {
            $clubId = $row['clubId'];
            if (!isset($topPerClub[$clubId])) {
                $topPerClub[$clubId] = [
                    'clubId'       => $clubId,
                    'score'        => (int) $row['score'],
                    'displayLabel' => $row['displayLabel'],
                ];
            }
        }

        return array_values($topPerClub);
    }
}

<?php

namespace App\Repository;

use App\Entity\LiveTelemetrySnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LiveTelemetrySnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LiveTelemetrySnapshot::class);
    }

    /**
     * Returns the single LiveTelemetrySnapshot row, creating a zeroed one if absent
     * (e.g. before the first app:telemetry:generate cron run).
     */
    public function getSnapshot(bool $flush = false): LiveTelemetrySnapshot
    {
        $snapshot = $this->findOneBy([]);
        if ($snapshot === null) {
            $snapshot = new LiveTelemetrySnapshot();
            $this->getEntityManager()->persist($snapshot);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        }
        return $snapshot;
    }
}

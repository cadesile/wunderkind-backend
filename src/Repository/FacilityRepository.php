<?php

namespace App\Repository;

use App\Entity\Academy;
use App\Entity\Facility;
use App\Enum\FacilityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FacilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Facility::class);
    }

    public function findByAcademyAndType(Academy $academy, FacilityType $type): ?Facility
    {
        return $this->findOneBy(['academy' => $academy, 'type' => $type]);
    }

    /** @return Facility[] */
    public function findAllByAcademy(Academy $academy): array
    {
        return $this->findBy(['academy' => $academy], ['type' => 'ASC']);
    }
}

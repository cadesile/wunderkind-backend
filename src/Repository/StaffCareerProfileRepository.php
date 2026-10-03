<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\StaffCareerProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffCareerProfile>
 */
class StaffCareerProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffCareerProfile::class);
    }

    public function findOrCreate(Club $club, string $staffId, string $staffName, string $staffRole): StaffCareerProfile
    {
        $profile = $this->findOneBy([
            'club'    => $club,
            'staffId' => $staffId,
        ]);

        if ($profile === null) {
            $profile = new StaffCareerProfile($club, $staffId, $staffName, $staffRole);
            $this->getEntityManager()->persist($profile);
        }

        return $profile;
    }
}

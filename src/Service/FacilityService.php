<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Academy;
use App\Entity\Facility;
use App\Enum\FacilityType;
use App\Repository\FacilityRepository;
use Doctrine\ORM\EntityManagerInterface;

class FacilityService
{
    public function __construct(
        private readonly FacilityRepository     $facilityRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return array<string, array> */
    public function getAcademyFacilitiesData(Academy $academy): array
    {
        $facilities = $this->facilityRepository->findAllByAcademy($academy);
        $data       = [];

        foreach ($facilities as $facility) {
            $data[$facility->getTypeValue()] = [
                'type'           => $facility->getTypeValue(),
                'level'          => $facility->getLevel(),
                'canUpgrade'     => $facility->canUpgrade(),
                'upgradeCost'    => $facility->getUpgradeCost(),
                'currentEffect'  => $facility->getCurrentEffect(),
                'lastUpgradedAt' => $facility->getLastUpgradedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $data;
    }

    /**
     * @throws \RuntimeException if already max level or insufficient funds
     */
    public function upgradeFacility(Facility $facility): void
    {
        if (!$facility->canUpgrade()) {
            throw new \RuntimeException('Facility is already at maximum level.');
        }

        $cost    = $facility->getUpgradeCost();
        $academy = $facility->getAcademy();

        if (!$academy->deductFunds($cost)) {
            throw new \RuntimeException('Insufficient funds to upgrade this facility.');
        }

        $facility->setLevel($facility->getLevel() + 1);
        $facility->setLastUpgradedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /** Initialize all facility types at level 0 for a new academy. */
    public function initializeFacilities(Academy $academy): void
    {
        foreach (FacilityType::cases() as $type) {
            $facility = new Facility($type, $academy);
            $this->em->persist($facility);
        }
    }
}

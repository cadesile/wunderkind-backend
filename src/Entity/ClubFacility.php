<?php

namespace App\Entity;

use App\Repository\ClubFacilityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: ClubFacilityRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_club_facility_slug', columns: ['club_id', 'facility_slug'])]
class ClubFacility
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Club $club;

    /** Canonical FacilityTemplate slug, e.g. 'technical_zone' */
    #[ORM\Column(name: 'facility_slug', length: 60)]
    private string $facilitySlug;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $level = 0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Club $club, string $facilitySlug, int $level = 0)
    {
        $this->id           = new UuidV7();
        $this->club         = $club;
        $this->facilitySlug = $facilitySlug;
        $this->level        = $level;
        $this->updatedAt     = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getFacilitySlug(): string { return $this->facilitySlug; }

    public function getLevel(): int { return $this->level; }
    public function setLevel(int $level): void
    {
        $this->level     = $level;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}

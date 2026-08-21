<?php

namespace App\Entity;

use App\Repository\AudienceGroupMemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Explicit membership of a Club in a MANUAL AudienceGroup. DYNAMIC groups do not use this
 * table — they are evaluated at poll time.
 */
#[ORM\Entity(repositoryClass: AudienceGroupMemberRepository::class)]
#[ORM\Table(name: 'audience_group_member')]
#[ORM\UniqueConstraint(name: 'uq_audience_member', columns: ['club_id', 'group_id'])]
class AudienceGroupMember
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Club $club;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'group_id', nullable: false, onDelete: 'CASCADE')]
    private AudienceGroup $group;

    #[ORM\Column]
    private \DateTimeImmutable $joinedAt;

    public function __construct(Club $club, AudienceGroup $group)
    {
        $this->id       = new UuidV7();
        $this->club     = $club;
        $this->group    = $group;
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7
    {
        return $this->id;
    }

    public function getClub(): Club
    {
        return $this->club;
    }

    public function getGroup(): AudienceGroup
    {
        return $this->group;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}

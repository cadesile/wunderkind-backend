<?php

namespace App\Entity;

use App\Repository\StaffCareerProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One currently-hired staff member's identity + avatar config for a club (sync v2).
 *
 * Staff rows in this app are pool-only and deleted on consumption (same hybrid-model
 * reasoning as PlayerCareerStat — see CLAUDE.md), so club rosters have no durable
 * server-side Staff row to attach this to. Keyed by the client-generated staffId string
 * instead — no FK to Staff.
 *
 * Unlike PlayerCareerStat, this carries no stats/performance columns — there is no
 * goals/assists/rating concept for staff. It exists purely to deliver each staff member's
 * appearanceConfig to any consumer that wants to render a club's actual hired staff
 * (an admin view, say), the same way PlayerCareerStat does for players today.
 */
#[ORM\Entity(repositoryClass: StaffCareerProfileRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_staff_career_profile_club_staff', columns: ['club_id', 'staff_id'])]
class StaffCareerProfile
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Club $club;

    /** Client-generated staff identity — not a FK to Staff. */
    #[ORM\Column(name: 'staff_id', length: 64)]
    private string $staffId;

    /** Denormalized display snapshot, refreshed each sync. */
    #[ORM\Column(name: 'staff_name', length: 100)]
    private string $staffName;

    /**
     * Free string, not the StaffRole enum — the client's role set is wider than the
     * backend's (e.g. 'scout', 'assistant_coach' are not StaffRole cases; Scout is a
     * separate entity backend-side). Stored verbatim, no validation, same trust model as
     * ledger[].category.
     */
    #[ORM\Column(name: 'staff_role', length: 30)]
    private string $staffRole;

    /**
     * Full sprite config as sent — staff persist outfit/trousers/glasses, unlike players'
     * personal-traits-only subset on PlayerCareerStat. Stored verbatim, no validation.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $appearanceConfig = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Club $club, string $staffId, string $staffName, string $staffRole)
    {
        $this->id        = new UuidV7();
        $this->club      = $club;
        $this->staffId   = $staffId;
        $this->staffName = $staffName;
        $this->staffRole = $staffRole;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getStaffId(): string { return $this->staffId; }
    public function getStaffName(): string { return $this->staffName; }
    public function getStaffRole(): string { return $this->staffRole; }
    public function getAppearanceConfig(): ?array { return $this->appearanceConfig; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * Identity fields are always present/required in the payload — overwrite
     * unconditionally. appearanceConfig is optional and handled separately by the caller
     * (only set when the incoming entry actually carries the key — see
     * SyncService::processStaffCareerProfiles()), so an older client omitting it doesn't
     * wipe out a value a newer client already sent.
     */
    public function refreshIdentity(string $staffName, string $staffRole): void
    {
        $this->staffName = $staffName;
        $this->staffRole = $staffRole;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setAppearanceConfig(?array $appearanceConfig): void
    {
        $this->appearanceConfig = $appearanceConfig;
        $this->updatedAt        = new \DateTimeImmutable();
    }
}

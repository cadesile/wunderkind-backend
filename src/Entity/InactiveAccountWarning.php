<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Evidence that a never-played account was emailed before app:users:prune-inactive deleted it.
 *
 * The prune refuses to delete an account without one of these whose delete_after has passed,
 * so a run that could not send the email delays a deletion rather than causing an unwarned one
 * (the same rule DormancyReclaimCommand applies to plots).
 *
 * RE-ARMABLE. If the account is seen again after warned_at (a login, a device check-in, a new
 * refresh token), the prune drops this row instead of deleting the account, so a later lapse
 * gets a fresh warning and a fresh 14 days. One row per account at most.
 *
 * Goes with the account (ON DELETE CASCADE); the lasting audit record is the DeletionRequest row
 * written with status PRUNED_INACTIVE.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inactive_account_warning')]
#[ORM\UniqueConstraint(name: 'uq_inactive_account_warning_user', columns: ['user_id'])]
class InactiveAccountWarning
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $warnedAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $deleteAfter;

    public function __construct(User $user, \DateTimeImmutable $warnedAt, \DateTimeImmutable $deleteAfter)
    {
        $this->id          = new UuidV7();
        $this->user        = $user;
        $this->warnedAt    = $warnedAt;
        $this->deleteAfter = $deleteAfter;
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getWarnedAt(): \DateTimeImmutable { return $this->warnedAt; }
    public function getDeleteAfter(): \DateTimeImmutable { return $this->deleteAfter; }
}

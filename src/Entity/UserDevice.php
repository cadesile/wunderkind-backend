<?php

namespace App\Entity;

use App\Enum\DevicePlatform;
use App\Repository\UserDeviceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * An FCM registration token for one app installation. `deviceToken` is unique across the
 * whole table (not per-user) — a token identifies an installation, not an account, so
 * registering an already-known token under a different authenticated user (e.g. logging into
 * a different club on the same phone) reassigns it rather than erroring; see
 * DeviceTokenController.
 */
#[ORM\Entity(repositoryClass: UserDeviceRepository::class)]
#[ORM\Table(name: 'user_device')]
#[ORM\Index(columns: ['user_id'], name: 'idx_user_device_user')]
class UserDevice
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 4096, unique: true)]
    private string $deviceToken;

    #[ORM\Column(type: 'string', length: 20, enumType: DevicePlatform::class)]
    private DevicePlatform $platform;

    /** Client-generated stable device id, for future device-scoped actions. Not an FCM concept. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $deviceId = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $lastActiveAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $deviceToken, DevicePlatform $platform, ?string $deviceId = null)
    {
        $this->id           = new UuidV7();
        $this->user         = $user;
        $this->deviceToken  = $deviceToken;
        $this->platform     = $platform;
        $this->deviceId     = $deviceId;
        $this->lastActiveAt = new \DateTimeImmutable();
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getDeviceToken(): string { return $this->deviceToken; }

    public function getPlatform(): DevicePlatform { return $this->platform; }
    public function setPlatform(DevicePlatform $platform): static { $this->platform = $platform; return $this; }

    public function getDeviceId(): ?string { return $this->deviceId; }
    public function setDeviceId(?string $deviceId): static { $this->deviceId = $deviceId; return $this; }

    public function getLastActiveAt(): \DateTimeImmutable { return $this->lastActiveAt; }
    public function touch(): static { $this->lastActiveAt = new \DateTimeImmutable(); return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

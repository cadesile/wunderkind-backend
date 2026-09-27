<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: '`user`')]
#[ORM\Index(name: 'idx_user_created_at', columns: ['created_at'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_CLUB = 'ROLE_CLUB';

    /**
     * Domain used for device-bound guest accounts (see GuestAuthService).
     * Accounts registered under this domain are auto-verified since the
     * address is synthetic and can never receive a real verification email.
     */
    public const GUEST_EMAIL_DOMAIN = '@guest.buildmyclub.local';

    public static function isGuestEmail(string $email): bool
    {
        return str_ends_with($email, self::GUEST_EMAIL_DOMAIN);
    }

    /**
     * Domain used for admin-generated spoof users backing spoof Clubs (see
     * CompetitionSpoofEntrantService). Never created by a real client flow.
     */
    public const SPOOF_EMAIL_DOMAIN = '@spoof.buildmyclub.local';

    public static function isSpoofEmail(string $email): bool
    {
        return str_ends_with($email, self::SPOOF_EMAIL_DOMAIN);
    }

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Club::class, cascade: ['persist', 'remove'])]
    private Collection $clubs;

    /** Owner identity — the real account holder's own name/nationality/gender/DOB, set via POST /api/owner-avatar. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $gender = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dob = null;

    /** Owner avatar (frontend Appearance shape). Null until generated/set. See "Avatar Appearance" in CLAUDE.md. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $appearance = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email)
    {
        $this->id        = new UuidV7();
        $this->email     = $email;
        $this->createdAt = new \DateTimeImmutable();
        $this->clubs     = new ArrayCollection();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }

    public function getUserIdentifier(): string { return $this->email; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }

    public function getRoles(): array { return array_unique($this->roles); }

    public function setRoles(array $roles): void { $this->roles = $roles; }

    public function eraseCredentials(): void {}

    /** @return Collection<int, Club> */
    public function getClubs(): Collection { return $this->clubs; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): void { $this->name = $name; }

    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }

    public function getGender(): ?string { return $this->gender; }
    public function setGender(?string $gender): void { $this->gender = $gender; }

    public function getDob(): ?\DateTimeImmutable { return $this->dob; }
    public function setDob(?\DateTimeImmutable $dob): void { $this->dob = $dob; }

    public function getAppearance(): ?array { return $this->appearance; }
    public function setAppearance(?array $appearance): void { $this->appearance = $appearance; }

    public function isVerified(): bool { return $this->isVerified; }
    public function setIsVerified(bool $isVerified): void { $this->isVerified = $isVerified; }

    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): void { $this->verifiedAt = $verifiedAt; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): void { $this->lastLoginAt = $lastLoginAt; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

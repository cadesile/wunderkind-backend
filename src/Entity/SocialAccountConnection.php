<?php

namespace App\Entity;

use App\Enum\SocialPlatform;
use App\Repository\SocialAccountConnectionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: SocialAccountConnectionRepository::class)]
#[ORM\Table(name: 'social_account_connection')]
#[ORM\UniqueConstraint(name: 'uq_social_platform_external_id', columns: ['platform', 'external_account_id'])]
class SocialAccountConnection
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'string', enumType: SocialPlatform::class)]
    private SocialPlatform $platform;

    /** Page name / handle shown in the admin UI. */
    #[ORM\Column(length: 255)]
    private string $displayName;

    /** Facebook Page ID or X (Twitter) user ID. */
    #[ORM\Column(length: 255)]
    private string $externalAccountId;

    /**
     * Ciphertext only — never plaintext. Encrypt via TokenEncryptionService
     * before calling setAccessToken(), decrypt after getAccessToken().
     */
    #[ORM\Column(type: 'text')]
    private string $accessToken;

    /** Ciphertext only, same contract as accessToken. Null for platforms with no refresh token (e.g. Facebook long-lived Page tokens). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $connectedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRefreshedAt = null;

    public function __construct(
        SocialPlatform $platform,
        string $displayName,
        string $externalAccountId,
        string $accessToken,
    ) {
        $this->id                = new UuidV7();
        $this->platform           = $platform;
        $this->displayName        = $displayName;
        $this->externalAccountId  = $externalAccountId;
        $this->accessToken        = $accessToken;
        $this->connectedAt        = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getPlatform(): SocialPlatform { return $this->platform; }

    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $v): static { $this->displayName = $v; return $this; }

    public function getExternalAccountId(): string { return $this->externalAccountId; }

    /** @return string Ciphertext — pass through TokenEncryptionService::decrypt() to use. */
    public function getAccessToken(): string { return $this->accessToken; }

    /** @param string $v Ciphertext — must already be encrypted via TokenEncryptionService::encrypt(). */
    public function setAccessToken(string $v): static { $this->accessToken = $v; return $this; }

    /** @return string|null Ciphertext — pass through TokenEncryptionService::decrypt() to use. */
    public function getRefreshToken(): ?string { return $this->refreshToken; }

    /** @param string|null $v Ciphertext — must already be encrypted via TokenEncryptionService::encrypt(). */
    public function setRefreshToken(?string $v): static { $this->refreshToken = $v; return $this; }

    public function getTokenExpiresAt(): ?\DateTimeImmutable { return $this->tokenExpiresAt; }
    public function setTokenExpiresAt(?\DateTimeImmutable $v): static { $this->tokenExpiresAt = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getConnectedAt(): \DateTimeImmutable { return $this->connectedAt; }

    public function getLastRefreshedAt(): ?\DateTimeImmutable { return $this->lastRefreshedAt; }
    public function setLastRefreshedAt(?\DateTimeImmutable $v): static { $this->lastRefreshedAt = $v; return $this; }
}

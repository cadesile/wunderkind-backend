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

    /**
     * Per-account override for the in-app debug log panel, for debugging one player's
     * device without turning the panel on for everyone.
     *
     * Only consulted when the GLOBAL GameConfig::$debugLoggingEnabled is OFF — with the
     * global switch on, the panel is already visible to every account and this flag adds
     * nothing. So the effective value the client receives is `global || per-user`, never
     * per-user alone: this flag can turn the panel ON for one account, and can never turn
     * it OFF for an account while the global switch is on. Resolved in
     * GameConfigController, which is the only place that combines the two.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $debugLoggingEnabled = false;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Bumped to invalidate every access token already issued to this account.
     *
     * The api firewall is stateless JWT, so there is no session to end — a
     * token stays valid until it expires no matter what the server does. Every
     * token carries this number as a `tv` claim (JwtTokenVersionSubscriber);
     * on each request the claim is compared against this column and a mismatch
     * rejects the token. Bumping it therefore logs the account out everywhere,
     * immediately, without a denylist of individual tokens.
     *
     * Starts at 0 and tokens minted before the claim existed have no `tv`,
     * which is read as 0 — so deploying this does not sign anyone out, while a
     * killed account's pre-existing tokens still stop working.
     *
     * Revoking the refresh tokens is the other half of the job and is NOT done
     * here; see SessionRevocationService, which owns both steps.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $tokenVersion = 0;

    /**
     * How many AI land valuations this account has spent.
     *
     * Each one is a billed external API call, so this is a spend meter, and
     * `StarterConfig::$maxEnrichmentRequestsPerUser` is the ceiling it is read against.
     *
     * COUNTS DISPATCHES, NOT REQUESTS, and the distinction is the whole design. A request that
     * finds the town already queued by somebody else — a double tap, a retry after a timeout,
     * two players opening the same town — bills nothing, so charging the player's quota for it
     * would take budget for an API call that never happened. The increment therefore sits behind
     * the same conditional UPDATE that decides who pays: whoever wins the claim is counted, and
     * everybody else is told the same `queued` and charged nothing. See
     * LocationEnrichmentService::queueOnRequest().
     *
     * LIFETIME TOTAL, NOT A WINDOW. It only ever goes up — there is no reset, no per-hour
     * bucket and no decay. That is the cheapest thing that satisfies the requirement (cap the
     * paid calls an account can make) and it needs no scheduled job to be correct. A rolling
     * window is a different feature and would want its own table of timestamps rather than a
     * counter; this column would not be the place to bolt it on.
     *
     * Starts at 0, which is also what every account predating the column reads as — so
     * deploying this gives existing accounts their full allowance rather than retroactively
     * billing them for valuations they already triggered. There is no backfill to do, and no
     * source to backfill from: enrichment attempts were never attributed to a user.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $enrichmentCount = 0;

    public function __construct(string $email)
    {
        $this->id        = new UuidV7();
        $this->email     = $email;
        $this->createdAt = new \DateTimeImmutable();
        $this->clubs     = new ArrayCollection();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getTokenVersion(): int { return $this->tokenVersion; }

    /** Invalidates every access token already issued. See $tokenVersion. */
    public function bumpTokenVersion(): void { $this->tokenVersion++; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }

    public function getUserIdentifier(): string { return $this->email; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): void { $this->password = $password; }

    /**
     * ROLE_CLUB is guaranteed here, not read out of the column — the same way Admin::getRoles()
     * hardcodes ROLE_ADMIN. A User row IS a game account (admins are a separate entity behind a
     * separate firewall), so the stored array carries no information that could withdraw that:
     * every creation path grants ROLE_CLUB and nothing has ever revoked it.
     *
     * It is guaranteed because the column has twice held something else. Accounts registered
     * before 6dc85ab (2026-03) stored `[]`, and accounts between 6dc85ab and 2a9e6cd (2026-04)
     * stored the since-deleted `ROLE_ACADEMY`; there is no role_hierarchy, so either value made
     * every `roles: ROLE_CLUB` route in security.yaml answer 403 to an account that had just
     * logged in successfully. That is indistinguishable, client-side, from a refused account —
     * see docs/api/role-club-403.md. Guaranteeing the role here makes that state stop existing
     * rather than needing a backfill per stale row.
     *
     * Consequence worth knowing: club access can no longer be withdrawn by emptying this column.
     * Nothing did that, and the login-time gate is isVerified() (see
     * VerificationAwareAuthenticationSuccessHandler) — a real ban would want its own flag, not a
     * role deletion. Setting roles to ["ROLE_ADMIN"] by hand (the EasyAdmin grant in CLAUDE.md)
     * now yields both roles rather than silently locking the account out of the game API.
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, self::ROLE_CLUB]));
    }

    public function setRoles(array $roles): void { $this->roles = $roles; }

    public function getEnrichmentCount(): int { return $this->enrichmentCount; }

    /**
     * One more valuation spent.
     *
     * No setter, deliberately: the only legitimate change to a spend meter is "one more", and an
     * absolute setter is how one caller clobbers another's increment. Writing it through the
     * entity rather than a bare UPDATE keeps it inside the same transaction as the claim, so a
     * rolled-back dispatch cannot leave the player charged for it.
     */
    public function recordEnrichmentRequest(): void { ++$this->enrichmentCount; }

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

    public function isDebugLoggingEnabled(): bool { return $this->debugLoggingEnabled; }
    public function setDebugLoggingEnabled(bool $debugLoggingEnabled): void { $this->debugLoggingEnabled = $debugLoggingEnabled; }

    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): void { $this->verifiedAt = $verifiedAt; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): void { $this->lastLoginAt = $lastLoginAt; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

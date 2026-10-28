<?php

declare(strict_types=1);

namespace App\Entity\Competition;

use App\Repository\Competition\CompetitionResponseCacheRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * The prebuilt, viewer-independent body of GET /api/competitions/{id}.
 *
 * A bracket response is a pure function of state that only changes on discrete events —
 * a registration, a withdrawal, a resubmission, a round drawn, results published, the
 * competition completing — and every one of those is either an API write or a cron pass.
 * So it can be built when it changes rather than reassembled per request.
 *
 * **Only one key of that response varies per viewer**: `viewer`, from
 * CompetitionController::buildViewerBlock(). `entrants`, `rounds` and the rest of the
 * summary are identical for everyone, so this row holds all of that and the request composes
 * `viewer` plus `serverTime` on top. Three single-row queries, against reassembling up to 64
 * entrants and 63 fixtures.
 *
 * Stored in the database, not in a Symfony cache pool, and that is not a style choice. Only
 * Postgres has a volume (docker-compose.prod.yml); `up -d` recreates the app container on
 * every deploy, so `var/cache` — including the filesystem-backed `cache.app` pool — is
 * destroyed each time, whatever cache.yaml's comment about persisting between deploys says.
 * A prewarmed response would vanish on deploy and only return on the next cron tick, leaving
 * a cold window for exactly the requests that used to exhaust the memory limit. This follows
 * CountryWorldPackCache instead, which has the same problem and the same solution.
 *
 * For a COMPLETED competition this row is also the archive: its document can never change
 * again, so there is no separate archival mechanism — the engine inputs behind it are pruned
 * and this is what continues to serve the bracket.
 */
#[ORM\Entity(repositoryClass: CompetitionResponseCacheRepository::class)]
#[ORM\Table(name: 'competition_response_cache')]
#[ORM\UniqueConstraint(name: 'uq_competition_response_cache_competition', columns: ['active_competition_id'])]
class CompetitionResponseCache
{
    /**
     * Bumped whenever the shape of the stored document changes.
     *
     * A cached document is a denormalised copy of a serializer's output, so changing that
     * serializer invalidates every row. Comparing this against the code's current constant
     * is what makes a stale row a miss rather than a wrong answer — the same guard
     * CountryWorldPackCache uses, and the reason app:competition:cache:warm can rebuild in
     * bulk after a deploy that changes the payload.
     */
    public const PAYLOAD_VERSION = 1;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\OneToOne(targetEntity: ActiveCompetition::class)]
    #[ORM\JoinColumn(name: 'active_competition_id', nullable: false, onDelete: 'CASCADE')]
    private ActiveCompetition $activeCompetition;

    /** @var array<string, mixed> The viewer-independent response body. */
    #[ORM\Column(type: 'json')]
    private array $payload;

    /**
     * The state version the document was built from — CompetitionController's own instance
     * version. A request recomputes this cheaply (one aggregate query) and compares; a
     * mismatch is a miss. That makes the cache self-validating rather than dependent on
     * every writer remembering to invalidate it.
     */
    #[ORM\Column(type: 'integer')]
    private int $stateVersion;

    #[ORM\Column(type: 'smallint')]
    private int $payloadVersion;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $generatedAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(ActiveCompetition $activeCompetition, array $payload, int $stateVersion)
    {
        $this->id                = new UuidV7();
        $this->activeCompetition = $activeCompetition;
        $this->payload           = $payload;
        $this->stateVersion      = $stateVersion;
        $this->payloadVersion    = self::PAYLOAD_VERSION;
        $this->generatedAt       = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getActiveCompetition(): ActiveCompetition { return $this->activeCompetition; }

    /** @return array<string, mixed> */
    public function getPayload(): array { return $this->payload; }

    public function getStateVersion(): int { return $this->stateVersion; }

    public function getPayloadVersion(): int { return $this->payloadVersion; }

    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }

    /**
     * @param array<string, mixed> $payload
     */
    public function replace(array $payload, int $stateVersion): static
    {
        $this->payload        = $payload;
        $this->stateVersion   = $stateVersion;
        $this->payloadVersion = self::PAYLOAD_VERSION;
        $this->generatedAt    = new \DateTimeImmutable();

        return $this;
    }

    /** A row is usable only if both the document shape and the state it was built from still match. */
    public function isFreshFor(int $stateVersion): bool
    {
        return $this->payloadVersion === self::PAYLOAD_VERSION && $this->stateVersion === $stateVersion;
    }
}

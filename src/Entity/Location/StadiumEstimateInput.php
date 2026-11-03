<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Repository\Location\StadiumEstimateInputRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * What one stadium estimate was computed from — the forensic half, split off deliberately.
 *
 * A SPLIT ROW, for the reason .context/stages/03_data/output/schema.md gives for the seven that
 * already exist: ORM 3 removed PARTIAL hydration, so every mapped column is decoded on every
 * read of its row. `inputsJson` is written once and read only when a human asks "why is this
 * town priced like that", while StadiumEstimate itself is read whenever a plot is quoted. Keeping
 * them in one row would make the hot path pay for the audit trail.
 *
 * StadiumEstimate declares NO inverse association to this, which is also deliberate: a OneToOne
 * inverse side cannot be proxied (the owning FK is on this table) and is therefore always fetched
 * eagerly regardless of fetch: LAZY — which would silently restore the whole cost. Fetch through
 * StadiumEstimateInputRepository, in bulk where more than one is needed.
 *
 * It exists at all because "believable realism" is a claim someone eventually has to audit. When
 * a valuation looks wrong, the question is whether the inputs were wrong or the model was, and
 * nothing else in the schema can answer it.
 */
#[ORM\Entity(repositoryClass: StadiumEstimateInputRepository::class)]
#[ORM\Table(name: 'stadium_estimate_input')]
class StadiumEstimateInput
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private StadiumEstimate $estimate;

    /**
     * Every value the formula read, plus the coefficients it applied.
     *
     * Coefficients are recorded alongside the inputs on purpose: `modelVersion` says WHICH
     * formula ran, but only the stored numbers let someone reproduce the arithmetic after those
     * constants have been retuned.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $inputsJson;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $inputsJson */
    public function __construct(StadiumEstimate $estimate, array $inputsJson)
    {
        $this->id         = new UuidV7();
        $this->estimate   = $estimate;
        $this->inputsJson = $inputsJson;
        $this->createdAt  = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getEstimate(): StadiumEstimate { return $this->estimate; }

    /** @return array<string, mixed> */
    public function getInputsJson(): array { return $this->inputsJson; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Pretty-printed, for the admin detail page only. */
    public function getInputsPretty(): string
    {
        return (string) json_encode($this->inputsJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

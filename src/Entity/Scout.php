<?php

namespace App\Entity;

use App\Repository\ScoutRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: ScoutRepository::class)]
class Scout
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dob = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(type: 'json')]
    private array $judgements = [];

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $experience = 0;

    /**
     * The scout's single 1-100 ABILITY — how good they are, as one figure.
     *
     * ADDED 2026-11-20 ON AN OWNER RULING, AND IT NAMES A QUANTITY THAT WAS ALREADY BEING
     * ASSUMED. *"It would help if we added an 'ability' property to scouts, as experience
     * doesn't match 1 to one."* Three app sites were already treating `experience` AS ability —
     * `SeasonBudgetService.ts` fed it into the staff wage band as though it were a coach's
     * `coachingAbility`, and two type comments called it "raw 0-100 ability from backend". So
     * this column is a correction, not an addition: it gives that quantity a home and lets
     * `experience` go back to meaning career length.
     *
     * DERIVED FROM `experience` + `judgements`, DETERMINISTICALLY, by ScoutAbilityCalculator —
     * never rolled, for the same reason `weeklySalary` is not: every scout in the world was
     * generated before this column existed, so the backfill has to compute from what is stored,
     * and a random roll would make a backfilled scout disagree with an identical freshly
     * generated one for no reason a player could see. Generation and backfill share one formula.
     *
     * NEVER 0. The clamp floor is 1, and the calculator's docblock explains why a zero is a
     * defect rather than a default here: a wage is derived from this downstream (A24), and 0
     * would price the scout at the very bottom of the band. The migration backfills every
     * existing row rather than landing a DEFAULT 0, so the column is never in a lying state.
     *
     * READ-ONLY IN THE ADMIN, deliberately — an editable derived field would be a second source
     * for one quantity with nothing saying which wins. See ScoutCrudController.
     */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 1])]
    private int $ability = 1;

    /**
     * Weekly wage in pence.
     *
     * ADDED 2026-11-05, AND IT CLOSES A REAL HOLE RATHER THAN ADDING DETAIL. Staff carry
     * `weeklySalary` and scouts carried nothing at all — not unexposed, absent — so any
     * projection of a club's running costs built from the market modelled every scout as free.
     * That is the gap that blocked the funding floor on POST /api/club/fund; see
     * docs/api/setup-flow.md.
     *
     * DERIVED FROM `experience`, DETERMINISTICALLY, by ScoutWageCalculator — not rolled. Every
     * scout in the world was generated before this column existed, so the backfill has to
     * compute a wage from what was stored; a random roll would make the backfilled figure
     * unreproducible and would differ from what the same scout would be worth if generated
     * today. Generation and backfill therefore share one formula.
     */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $weeklySalary = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Avatar appearance (frontend Appearance shape). Null until generated/backfilled. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $appearance = null;

    /**
     * 8-spoke Personality Matrix, identical to Player's. Auto-generated on
     * persist by PersonalityLifecycleSubscriber, anchored on experience.
     */
    #[ORM\Embedded(class: PersonalityProfile::class)]
    private PersonalityProfile $personality;

    public function __construct(string $name = '')
    {
        $this->id        = new UuidV7();
        $this->name      = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->personality = new PersonalityProfile();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getPersonality(): PersonalityProfile { return $this->personality; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function getWeeklySalary(): int { return $this->weeklySalary; }
    public function setWeeklySalary(int $pence): void { $this->weeklySalary = max(0, $pence); }

    public function getDob(): ?\DateTimeImmutable { return $this->dob; }
    public function setDob(?\DateTimeImmutable $dob): void { $this->dob = $dob; }

    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $nationality): void { $this->nationality = $nationality; }

    public function getJudgements(): array { return $this->judgements; }
    public function setJudgements(array $judgements): void { $this->judgements = $judgements; }

    public function getJudgementsJson(): string
    {
        return json_encode($this->judgements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function setJudgementsJson(?string $json): void
    {
        $decoded = json_decode(trim($json ?? ''), true);
        $this->judgements = is_array($decoded) ? $decoded : [];
    }

    public function getExperience(): int { return $this->experience; }
    public function setExperience(int $experience): void { $this->experience = $experience; }

    public function getAbility(): int { return $this->ability; }

    /** Clamped on write as well as in the calculator — the column is unsigned and 0 is not legal. */
    public function setAbility(int $ability): void { $this->ability = max(1, min(100, $ability)); }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getAppearance(): ?array { return $this->appearance; }
    public function setAppearance(?array $appearance): void { $this->appearance = $appearance; }
}

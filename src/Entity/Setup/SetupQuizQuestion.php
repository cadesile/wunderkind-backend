<?php

declare(strict_types=1);

namespace App\Entity\Setup;

use App\Repository\Setup\SetupQuizQuestionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One question in the new-game onboarding quiz.
 *
 * WHAT THE QUIZ IS FOR. It is the only place in setup where the player can increase their own
 * opening credit: answering it writes a QUIZ_BONUS entry worth a configured multiple of the
 * account's resolved starting grant, scaled by how much was answered correctly. So these rows
 * are not copy — they are the input to a money calculation, which is why `correctIndex` lives
 * here on the server and is never serialised to a client.
 *
 * AN ENTITY RATHER THAN A JSON COLUMN, unlike the sibling setup TUTORIAL (whose 15 items sit in
 * `starter_config.setup_tutorial_config`). The tutorial is inert copy with no server-side
 * consequence; a quiz question has a right answer that decides a payout, needs per-row
 * activation so a bad question can be withdrawn without rewriting a blob, and is answered
 * against a foreign key. A json blob gives none of those.
 *
 * TEXT IS STORED, NOT TRANSLATION KEYS. The setup tutorial stores `ngs.tut.*` keys resolved
 * from the client's catalogue; this stores the English prompt and options directly, because the
 * quiz has no shipped copy namespace to resolve against. If it needs localising, the pattern to
 * follow is FacilityTemplate's — a TranslatableEntityType case and NarrativeTranslationService,
 * resolved server-side at read time, not a second copy of the strings.
 */
#[ORM\Entity(repositoryClass: SetupQuizQuestionRepository::class)]
#[ORM\Table(name: 'setup_quiz_question')]
class SetupQuizQuestion
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'text')]
    private string $prompt = '';

    /**
     * The answers offered, in display order, as a list of plain strings.
     *
     * A LIST, NOT A MAP, and the index is the identity: an answer is submitted as the ordinal
     * the client rendered. Reordering the options of a question that already has answers
     * therefore rewrites what those answers meant — so the admin form says so, and a question
     * in that state should be withdrawn and replaced rather than edited.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    /** Index into $options. SERVER-ONLY — never serialised to a client. */
    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $correctIndex = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sortOrder = 0;

    /**
     * Withdrawn questions stay in the table.
     *
     * Deactivating rather than deleting is the only safe retirement: answers reference the row,
     * and a bonus already paid was computed against a question count that included it. An
     * inactive question is excluded from what is served and from the denominator of any FUTURE
     * score, and leaves paid bonuses alone.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $prompt = '', array $options = [], int $correctIndex = 0)
    {
        $this->id           = new UuidV7();
        $this->prompt       = $prompt;
        $this->options      = array_values($options);
        $this->correctIndex = max(0, $correctIndex);
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getPrompt(): string { return $this->prompt; }
    public function setPrompt(string $prompt): static { $this->prompt = $prompt; return $this; }

    /** @return list<string> */
    public function getOptions(): array { return array_values($this->options); }

    /** @param list<string> $options */
    public function setOptions(array $options): static { $this->options = array_values($options); return $this; }

    public function getCorrectIndex(): int { return $this->correctIndex; }
    public function setCorrectIndex(int $index): static { $this->correctIndex = max(0, $index); return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $order): static { $this->sortOrder = $order; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): static { $this->isActive = $active; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * Whether a submitted ordinal is the right one.
     *
     * A question whose correctIndex points past the end of its options can never be answered
     * correctly — a misconfiguration, and it fails closed (nobody is paid for it) rather than
     * open. SetupQuizQuestionRepository::findServable() is what keeps such a row out of the
     * served set in the first place.
     */
    public function isCorrect(int $chosenIndex): bool
    {
        return $this->isAnswerable() && $chosenIndex === $this->correctIndex;
    }

    /** A question is only usable if its correct answer actually exists among its options. */
    public function isAnswerable(): bool
    {
        return $this->options !== [] && isset($this->options[$this->correctIndex]);
    }

    /**
     * The client-safe shape. NO `correctIndex` — this is the whole reason the method exists
     * rather than each controller assembling its own array and one of them forgetting.
     *
     * `question` IS AN ALIAS OF `prompt`, and both are sent. The shipped client reads
     * `question`; the richer list shape documented in docs/api/club-setup.md reads `prompt`.
     * Sending one and not the other would break one of the two readers, and the backend ships
     * ahead of the app.
     *
     * @return array{id: string, prompt: string, question: string, options: list<string>}
     */
    public function toClientArray(): array
    {
        return [
            'id'       => (string) $this->id,
            'prompt'   => $this->prompt,
            'question' => $this->prompt,
            'options'  => $this->getOptions(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Entity\Setup;

use App\Entity\User;
use App\Repository\Setup\SetupQuizAnswerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One account's answer to one quiz question.
 *
 * THE UNIQUE CONSTRAINT IS THE WHOLE FEATURE. docs/api/setup-flow.md settled that the server
 * will not trust the client's 5-second timer and will instead enforce "one attempt per account,
 * ever" — this is that enforcement, as a database constraint rather than a check that can be
 * raced. Two concurrent submissions for the same (user, question) cannot both land.
 *
 * ALSO WHAT MAKES A MID-QUIZ KILL RESUMABLE. Progress is these rows, so GET /api/setup-quiz can
 * tell the client which questions are already answered without a progress column that could
 * disagree with them. Same principle as GET /api/club/setup-state deriving from the ledger
 * rather than from flags.
 *
 * NOT CLUB-SCOPED. The quiz is a fact about the ACCOUNT, like the starting grant it multiplies
 * — an account has many clubs over its life and takes the quiz once. There is deliberately no
 * club column.
 *
 * ON DELETE CASCADE on `user_id` is what makes account deletion correct without touching
 * AccountDeletionService: that service leans on the database cascade for user-scoped tables
 * (see its docblock on user_ledger), so a new table with the cascade needs no new DQL there.
 */
#[ORM\Entity(repositoryClass: SetupQuizAnswerRepository::class)]
#[ORM\Table(name: 'setup_quiz_answer')]
#[ORM\UniqueConstraint(name: 'uq_setup_quiz_answer_user_question', columns: ['user_id', 'question_id'])]
class SetupQuizAnswer
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: SetupQuizQuestion::class)]
    #[ORM\JoinColumn(name: 'question_id', nullable: false, onDelete: 'CASCADE')]
    private SetupQuizQuestion $question;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
    private int $chosenIndex;

    /**
     * Graded at submission time and STORED, not recomputed on read.
     *
     * Deliberate: an admin can correct a question's `correctIndex` after the fact, and that
     * must not retroactively change a grade a bonus has already been paid against. The stored
     * boolean is what the payout was computed from, so it is what stays true.
     */
    #[ORM\Column]
    private bool $isCorrect;

    #[ORM\Column]
    private \DateTimeImmutable $answeredAt;

    public function __construct(User $user, SetupQuizQuestion $question, int $chosenIndex)
    {
        $this->id          = new UuidV7();
        $this->user        = $user;
        $this->question    = $question;
        $this->chosenIndex = max(0, $chosenIndex);
        $this->isCorrect   = $question->isCorrect($chosenIndex);
        $this->answeredAt  = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getQuestion(): SetupQuizQuestion { return $this->question; }
    public function getChosenIndex(): int { return $this->chosenIndex; }
    public function isCorrect(): bool { return $this->isCorrect; }
    public function getAnsweredAt(): \DateTimeImmutable { return $this->answeredAt; }
}

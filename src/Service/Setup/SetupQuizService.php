<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Entity\Setup\SetupQuizAnswer;
use App\Entity\Setup\SetupQuizQuestion;
use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Exception\Setup\SetupActionException;
use App\Repository\Setup\SetupQuizAnswerRepository;
use App\Repository\Setup\SetupQuizQuestionRepository;
use App\Repository\StarterConfigRepository;
use App\Repository\UserLedgerRepository;
use App\Service\StartingGrantService;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The onboarding quiz, and the credit it pays.
 *
 * THE BONUS IS A MULTIPLE OF THE ACCOUNT'S OWN GRANT, scaled by the share answered correctly:
 *
 *     bonus = resolveUserStartingBalancePence(account) x quizBonusMultiplier x (correct / total)
 *
 * Settled in docs/api/setup-flow.md #4, and the "x grant" half is not a style choice. The grant
 * is a RANGE resolved per account (StarterConfig::resolveUserStartingBalancePence), so the
 * client cannot know what an account received and a fixed bonus would be a different proportion
 * of every player's opening credit. A multiple of their own figure is the same reward to
 * everyone — and the client never computes it, it reads whatever the ledger says.
 *
 * ONE ATTEMPT PER ACCOUNT, ENFORCED TWICE AND DELIBERATELY SO. The server does not trust the
 * client's 5-second timer, as the prototype note anticipated. What it enforces instead is:
 *
 *  - per question, by `uq_setup_quiz_answer_user_question` — a unique index, so two concurrent
 *    submissions cannot both land and a re-answer is a refusal rather than an overwrite;
 *  - per payout, by the ledger's own idempotency on
 *    (sourceKind, sourceRef, type) = ('quiz_bonus', userId, QUIZ_BONUS) — so the bonus is
 *    written at most once however many times the last answer is submitted.
 *
 * PAID ON THE LAST ANSWER, NOT ON A SEPARATE "FINISH" CALL. A client that is killed between the
 * last answer and a finish call would otherwise lose the bonus with no way to claim it, and
 * resuming would show a completed quiz that paid nothing.
 *
 * A BONUS ALREADY PAID IS NEVER RECOMPUTED. If an admin adds a question afterwards, the account
 * has an unanswered question and a settled payout; the ledger guard means the new question
 * cannot top it up. That is the intended behaviour — the alternative is paying twice — and it
 * is why withdrawing a bad question is a deactivation rather than an edit.
 */
final class SetupQuizService
{
    /** Shared by the ledger row's sourceKind and the reads that look the bonus up. */
    public const SOURCE_KIND = 'quiz_bonus';

    public function __construct(
        private readonly SetupQuizQuestionRepository $questionRepository,
        private readonly SetupQuizAnswerRepository   $answerRepository,
        private readonly StarterConfigRepository     $starterConfigRepository,
        private readonly StartingGrantService        $startingGrantService,
        private readonly UserLedgerRepository        $ledgerRepository,
        private readonly UserLedgerService           $ledgerService,
        private readonly EntityManagerInterface      $em,
        private readonly LoggerInterface             $logger,
    ) {}

    /**
     * The quiz as this account sees it: the questions, what they have already answered, and
     * what the bonus is worth so far.
     *
     * NO `correctIndex` ANYWHERE IN HERE — the shape comes from
     * SetupQuizQuestion::toClientArray(), which exists so that guarantee lives in one place
     * rather than in each caller's array literal. The answered questions do carry back the
     * ordinal the player chose and whether it was right, because they have already committed
     * to it and the screen has to be able to show the result on resume.
     *
     * SERVES TWO SHAPES AT ONCE, DELIBERATELY. Alongside `questions`, the next unanswered
     * question is ALSO flattened onto the top level as `{id, question, options}`, and every item
     * in the list carries `question` as an alias of `prompt`.
     *
     * That is not redundancy for its own sake. The shipped client reads a SINGLE question off
     * the top level and would see `id` as undefined against a bare list — and the backend ships
     * ahead of the app, so the list shape alone would have broken the one screen a new account
     * cannot skip. Both shapes are correct and agree: with exactly one question published they
     * describe the identical quiz, and the proportional bonus over a single question is the same
     * figure as the client's all-or-nothing reading of it.
     *
     * The top-level block is null-filled rather than omitted when there is nothing left to
     * answer, so a client reading `id` gets null (a finished quiz) rather than a stale question.
     *
     * @return array{id: string|null, question: string|null, options: list<string>,
     *     questions: list<array<string, mixed>>, total: int, answered: int,
     *     correct: int, complete: bool, bonus: array{paid: bool, pence: int, multiplier: float}}
     */
    public function quizFor(User $user): array
    {
        $questions = $this->questionRepository->findServable();
        $answers   = $this->answerRepository->findForUserKeyedByQuestion($user);

        $rows    = [];
        $correct = 0;
        $done    = 0;
        $next    = null;

        foreach ($questions as $question) {
            $answer = $answers[(string) $question->getId()] ?? null;
            $row    = $question->toClientArray();

            $row['answered'] = $answer !== null;

            if ($answer !== null) {
                ++$done;
                $correct        += $answer->isCorrect() ? 1 : 0;
                $row['chosenIndex'] = $answer->getChosenIndex();
                $row['correct']     = $answer->isCorrect();
            } elseif ($next === null) {
                // First unanswered question, in served order — what the single-question client
                // reads off the top level, and what makes a resumed quiz pick up where it was.
                $next = $question;
            }

            $rows[] = $row;
        }

        $paid = $this->paidBonusPence($user);

        return [
            // The single-question shape. See the docblock: the shipped client reads these.
            'id'        => $next === null ? null : (string) $next->getId(),
            'question'  => $next?->getPrompt(),
            'options'   => $next === null ? [] : $next->getOptions(),
            'questions' => $rows,
            'total'     => count($questions),
            'answered'  => $done,
            'correct'   => $correct,
            'complete'  => $questions !== [] && $done >= count($questions),
            'bonus'     => [
                'paid'       => $paid !== null,
                // What was actually paid once it has been; what it would be worth on the
                // answers so far before that. Never a projection of a perfect score — the
                // client would show a number the player then failed to earn.
                'pence'      => $paid ?? $this->bonusPence($user, $correct, count($questions)),
                'multiplier' => $this->starterConfigRepository->getConfig()->getQuizBonusMultiplier(),
            ],
        ];
    }

    /**
     * Record one answer, and pay the bonus if that was the last question.
     *
     * @return array{questionId: string, correct: bool, answered: int, total: int,
     *     complete: bool, bonus: array{paid: bool, pence: int, multiplier: float}}
     *
     * @throws SetupActionException
     */
    public function answer(User $user, SetupQuizQuestion $question, int $chosenIndex): array
    {
        if (!$question->isActive() || !$question->isAnswerable()) {
            // A withdrawn or misconfigured question is not answerable. Reported as unavailable
            // rather than as a wrong answer, which would cost the player part of their bonus
            // for an operator's mistake.
            throw SetupActionException::quizUnavailable();
        }

        $options = $question->getOptions();

        if ($chosenIndex < 0 || !isset($options[$chosenIndex])) {
            throw SetupActionException::invalidAnswer($chosenIndex, count($options));
        }

        if ($this->answerRepository->findOneForUserAndQuestion($user, $question) !== null) {
            throw SetupActionException::alreadyAnswered();
        }

        $answer = new SetupQuizAnswer($user, $question, $chosenIndex);

        try {
            $this->em->persist($answer);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Lost the race against a concurrent submission of the same question. The check
            // above is the friendly path; the index is the truth, and both answers landing
            // would let one account answer twice.
            throw SetupActionException::alreadyAnswered();
        }

        $questions = $this->questionRepository->findServable();
        $answers   = $this->answerRepository->findForUserKeyedByQuestion($user);

        $correct = 0;
        $done    = 0;

        foreach ($questions as $servable) {
            $given = $answers[(string) $servable->getId()] ?? null;

            if ($given !== null) {
                ++$done;
                $correct += $given->isCorrect() ? 1 : 0;
            }
        }

        $complete = $questions !== [] && $done >= count($questions);
        $paid     = $this->paidBonusPence($user);

        if ($complete && $paid === null) {
            $paid = $this->payBonus($user, $correct, count($questions));
        }

        return [
            'questionId' => (string) $question->getId(),
            'correct'    => $answer->isCorrect(),
            'answered'   => $done,
            'total'      => count($questions),
            'complete'   => $complete,
            'bonus'      => [
                'paid'       => $paid !== null,
                'pence'      => $paid ?? $this->bonusPence($user, $correct, count($questions)),
                'multiplier' => $this->starterConfigRepository->getConfig()->getQuizBonusMultiplier(),
            ],
        ];
    }

    /**
     * What a score is worth, in pence.
     *
     * Rounded once, at the end, on the account's own grant — so the figure is reproducible from
     * stored data (the grant is deterministic from the user id) plus the two stored counts.
     */
    public function bonusPence(User $user, int $correct, int $total): int
    {
        if ($total <= 0 || $correct <= 0) {
            return 0;
        }

        $multiplier = $this->starterConfigRepository->getConfig()->getQuizBonusMultiplier();

        if ($multiplier <= 0.0) {
            // A legitimate "bonus off" setting. The quiz still runs and still records answers.
            return 0;
        }

        $grantPence = $this->startingGrantService->amountFor($user);

        return (int) round($grantPence * $multiplier * min($correct, $total) / $total);
    }

    /** The bonus this account has already been paid, or null if it has not been. */
    public function paidBonusPence(User $user): ?int
    {
        $entry = $this->ledgerRepository->findByBackendSource(
            self::SOURCE_KIND,
            (string) $user->getId(),
            UserLedgerEntryType::QUIZ_BONUS,
        );

        return $entry === null ? null : $entry->getAmountPence();
    }

    /**
     * Write the bonus.
     *
     * NEVER THROWS INTO THE CALLER, for the same reason StartingGrantService does not: the quiz
     * is part of the one screen sequence a new player cannot skip, and failing their last answer
     * over a credit they have not tried to spend yet would strand them. A failure is logged, the
     * answer still stands, and the recorded answers mean the payout can be reconciled later.
     */
    private function payBonus(User $user, int $correct, int $total): ?int
    {
        $pence = $this->bonusPence($user, $correct, $total);

        if ($pence <= 0) {
            return null;
        }

        try {
            return $this->ledgerService->append(
                $user,
                // No club. The bonus tops up the ACCOUNT, like the grant it is a multiple of,
                // and predates any club the player goes on to found.
                null,
                UserLedgerEntryType::QUIZ_BONUS,
                $pence,
                self::SOURCE_KIND,
                (string) $user->getId(),
                sprintf('Setup quiz bonus (%d of %d correct)', $correct, $total),
            )->getAmountPence();
        } catch (\Throwable $e) {
            $this->logger->error('Could not write setup quiz bonus', [
                'userId'  => (string) $user->getId(),
                'pence'   => $pence,
                'correct' => $correct,
                'total'   => $total,
                'exception' => $e,
            ]);

            return null;
        }
    }
}

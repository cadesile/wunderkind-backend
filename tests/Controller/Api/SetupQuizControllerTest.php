<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Setup\SetupQuizQuestion;
use App\Entity\User;
use App\Enum\UserLedgerEntryType;
use App\Service\UserLedgerService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/setup-quiz and POST /api/setup-quiz/{id}/answer.
 *
 * THREE GROUPS OF ASSERTION, each guarding something that fails quietly:
 *
 *  1. THE CORRECT ANSWER NEVER LEAVES THE SERVER. A leaked `correctIndex` turns the bonus into
 *     free money for anyone reading the response, and nothing would look broken.
 *  2. ONE ATTEMPT PER ACCOUNT, EVER. Enforced by a unique index rather than the client's timer.
 *  3. THE BONUS IS PAID ONCE, proportional to the score, and on the account's OWN grant — the
 *     client cannot compute it, so a wrong figure here is undetectable client-side.
 */
class SetupQuizControllerTest extends WebTestCase
{
    private const EMAIL_PREFIX = 'quiz-';
    /** Both ends equal, so the account's grant is a known figure rather than a range. */
    private const GRANT_PENCE = 1_000_000;

    private KernelBrowser $client;
    private string $currentUserId = '';
    private EntityManagerInterface $em;
    private Connection $connection;
    /** @var list<string> */
    private array $questionIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $c                = self::getContainer();
        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);

        $this->purge();
        $this->pinGrantAndMultiplier(self::GRANT_PENCE, 1.0);
    }

    protected function tearDown(): void
    {
        $this->purge();
        $this->restoreConfig();

        parent::tearDown();
    }

    // ───────────────── the correct answer stays server-side ─────────────────

    public function testTheQuizNeverExposesTheCorrectAnswer(): void
    {
        $this->seedQuestions(2);
        $this->account();

        $this->authedRequest('GET', '/api/setup-quiz');

        self::assertResponseIsSuccessful();
        $raw = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('correctIndex', $raw);

        foreach ($this->json()['questions'] as $question) {
            self::assertArrayNotHasKey('correctIndex', $question);
            self::assertArrayHasKey('options', $question);
        }
    }

    public function testAnsweringDoesNotLeakTheCorrectAnswerEither(): void
    {
        $this->seedQuestions(1);
        $this->account();

        // Deliberately wrong: the response says so, but must not say what the right one was.
        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 1]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['correct']);
        self::assertStringNotContainsString('correctIndex', (string) $this->client->getResponse()->getContent());
    }

    // ────────────── the shipped client's single-question shape ──────────────

    /**
     * The next unanswered question is ALSO flattened onto the top level.
     *
     * THE COMPATIBILITY GUARANTEE. The shipped app reads a single `{id, question, options}` off
     * the top level; against a bare `{questions: [...]}` it would see `id` as undefined and the
     * one screen a new account cannot skip would break. The backend ships ahead of the app, so
     * both shapes are served and both must stay served.
     */
    public function testTheNextQuestionIsAlsoServedAtTheTopLevel(): void
    {
        $this->seedQuestions(2);
        $this->account();

        $this->authedRequest('GET', '/api/setup-quiz');
        self::assertResponseIsSuccessful();

        $body = $this->json();

        self::assertSame($this->questionIds[0], $body['id'], 'top-level id is the first unanswered question');
        self::assertSame('Test question 1?', $body['question']);
        self::assertSame(['right', 'wrong', 'also wrong'], $body['options']);

        // And the list shape is still there alongside it.
        self::assertCount(2, $body['questions']);
        // `question` is an alias of `prompt` on every item, so either reader works.
        self::assertSame($body['questions'][0]['prompt'], $body['questions'][0]['question']);
    }

    /** The top-level block advances as questions are answered — that is what makes resume work. */
    public function testTheTopLevelQuestionAdvancesWithProgress(): void
    {
        $this->seedQuestions(2);
        $this->account();

        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 0]);
        self::assertResponseIsSuccessful();

        $this->authedRequest('GET', '/api/setup-quiz');
        self::assertResponseIsSuccessful();

        self::assertSame($this->questionIds[1], $this->json()['id'], 'moves on rather than repeating');
    }

    /**
     * A finished quiz serves a NULL top-level question, not a stale one.
     *
     * A client reading `id` gets null and can tell the quiz is done; repeating the last question
     * would invite an answer that is then refused as already answered.
     */
    public function testAFinishedQuizServesANullTopLevelQuestion(): void
    {
        $this->seedQuestions(1);
        $this->account();

        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 0]);
        self::assertResponseIsSuccessful();

        $this->authedRequest('GET', '/api/setup-quiz');
        self::assertResponseIsSuccessful();

        $body = $this->json();
        self::assertNull($body['id']);
        self::assertNull($body['question']);
        self::assertSame([], $body['options']);
        self::assertTrue($body['complete']);
    }

    /**
     * With ONE question published, the two readings agree exactly.
     *
     * The proportional bonus over a single question (correct/total = 1/1) is the same figure as
     * the client's all-or-nothing reading of it, so an operator publishing one question gets a
     * quiz both implementations score identically. That is what makes the shape divergence safe
     * to resolve additively instead of one side having to move.
     */
    public function testASingleQuestionScoresTheSameUnderBothReadings(): void
    {
        $this->seedQuestions(1);
        $this->account();

        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 0]);
        self::assertResponseIsSuccessful();

        self::assertSame(self::GRANT_PENCE, $this->json()['bonus']['pence'], 'a perfect single answer doubles the grant');
    }

    // ───────────────────────── one attempt ever ─────────────────────────

    public function testAQuestionCannotBeAnsweredTwice(): void
    {
        $this->seedQuestions(2);
        $this->account();

        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 0]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['correct']);

        // A second go at the same question — the retry a player would make to fix a wrong answer.
        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 1]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_answered', $this->json()['error']);

        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM setup_quiz_answer WHERE user_id = ?',
                [$this->currentUserId],
            ),
        );
    }

    /** Progress is the answer rows, so a killed client resumes against the truth. */
    public function testProgressIsReportedForResume(): void
    {
        $this->seedQuestions(3);
        $this->account();

        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 0]);
        self::assertResponseIsSuccessful();

        $this->authedRequest('GET', '/api/setup-quiz');
        self::assertResponseIsSuccessful();

        $body = $this->json();
        self::assertSame(3, $body['total']);
        self::assertSame(1, $body['answered']);
        self::assertSame(1, $body['correct']);
        self::assertFalse($body['complete']);

        $byId = [];
        foreach ($body['questions'] as $question) {
            $byId[$question['id']] = $question;
        }

        self::assertTrue($byId[$this->questionIds[0]]['answered']);
        self::assertSame(0, $byId[$this->questionIds[0]]['chosenIndex']);
        self::assertTrue($byId[$this->questionIds[0]]['correct']);
        self::assertFalse($byId[$this->questionIds[1]]['answered']);
        // An unanswered question carries no chosenIndex to mistake for an answer of zero.
        self::assertArrayNotHasKey('chosenIndex', $byId[$this->questionIds[1]]);
    }

    public function testAnAnswerOutsideTheOptionsIsRefused(): void
    {
        $this->seedQuestions(1);
        $this->account();

        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 7]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_answer', $this->json()['error']);
    }

    public function testAnUnknownQuestionIs404(): void
    {
        $this->seedQuestions(1);
        $this->account();

        $this->authedRequest('POST', '/api/setup-quiz/0199bb11-2233-7444-8555-666677778888/answer', ['answer' => 0]);

        self::assertResponseStatusCodeSame(404);
    }

    // ───────────────────────── the bonus ─────────────────────────

    /** A perfect score at multiplier 1.0 doubles the grant. */
    public function testAPerfectScorePaysTheWholeMultiplier(): void
    {
        $this->seedQuestions(2);
        $this->account();

        $this->answerAll(correct: 2, total: 2);

        self::assertSame(
            self::GRANT_PENCE,
            (int) $this->connection->fetchOne(
                "SELECT amount_pence FROM user_ledger WHERE type = 'quiz_bonus' AND user_id = ?",
                [$this->currentUserId],
            ),
        );
    }

    /** Half marks pay half — the proportional rule, not all-or-nothing. */
    public function testAPartialScorePaysProportionally(): void
    {
        $this->seedQuestions(2);
        $this->account();

        $this->answerAll(correct: 1, total: 2);

        $body = $this->json();
        self::assertTrue($body['complete']);
        self::assertTrue($body['bonus']['paid']);
        self::assertSame(intdiv(self::GRANT_PENCE, 2), $body['bonus']['pence']);

        self::assertSame(
            intdiv(self::GRANT_PENCE, 2),
            (int) $this->connection->fetchOne(
                "SELECT amount_pence FROM user_ledger WHERE type = 'quiz_bonus' AND user_id = ?",
                [$this->currentUserId],
            ),
        );
    }

    /** Nothing right, nothing paid — and no zero-value ledger row. */
    public function testAllWrongPaysNothing(): void
    {
        $this->seedQuestions(2);
        $this->account();

        $this->answerAll(correct: 0, total: 2);

        self::assertFalse($this->json()['bonus']['paid']);
        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM user_ledger WHERE type = 'quiz_bonus' AND user_id = ?",
                [$this->currentUserId],
            ),
        );
    }

    /** The bonus lands on the LAST answer, not on a separate finish call. */
    public function testTheBonusIsNotPaidBeforeTheQuizIsComplete(): void
    {
        $this->seedQuestions(2);
        $this->account();

        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 0]);
        self::assertResponseIsSuccessful();

        self::assertFalse($this->json()['complete']);
        self::assertFalse($this->json()['bonus']['paid']);
        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM user_ledger WHERE type = 'quiz_bonus' AND user_id = ?",
                [$this->currentUserId],
            ),
        );
    }

    /**
     * Re-submitting the last answer cannot pay twice.
     *
     * The per-question index refuses the duplicate first, and the ledger's own idempotency on
     * ('quiz_bonus', userId) is the second guard behind it.
     */
    public function testTheBonusIsPaidOnlyOnce(): void
    {
        $this->seedQuestions(1);
        $this->account();

        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 0]);
        self::assertResponseIsSuccessful();

        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 0]);
        self::assertResponseStatusCodeSame(409);

        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM user_ledger WHERE type = 'quiz_bonus' AND user_id = ?",
                [$this->currentUserId],
            ),
        );
    }

    /** A zero multiplier is a legitimate "bonus off": the quiz still records answers. */
    public function testAZeroMultiplierRunsTheQuizAndPaysNothing(): void
    {
        $this->seedQuestions(1);
        $this->pinGrantAndMultiplier(self::GRANT_PENCE, 0.0);
        $this->account();

        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[0]}/answer", ['answer' => 0]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['correct']);
        self::assertTrue($this->json()['complete']);
        self::assertFalse($this->json()['bonus']['paid']);

        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM setup_quiz_answer WHERE user_id = ?',
                [$this->currentUserId],
            ),
            'answers are still recorded with the bonus switched off',
        );
    }

    /** An inactive question is not served and cannot be answered. */
    public function testAWithdrawnQuestionIsNeitherServedNorAnswerable(): void
    {
        $this->seedQuestions(2);
        $this->account();

        $withdrawn = $this->em->find(SetupQuizQuestion::class, $this->questionIds[1]);
        $withdrawn->setIsActive(false);
        $this->em->flush();

        $this->authedRequest('GET', '/api/setup-quiz');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['total']);

        $this->authedRequest('POST', "/api/setup-quiz/{$this->questionIds[1]}/answer", ['answer' => 0]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('quiz_unavailable', $this->json()['error']);
    }

    /** No questions published is a valid empty quiz, not an error. */
    public function testAnEmptyQuizIsServedRatherThanFailing(): void
    {
        $this->account();

        $this->authedRequest('GET', '/api/setup-quiz');

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame([], $body['questions']);
        self::assertSame(0, $body['total']);
        // Not "complete" — there is nothing to complete, and a true here would make a client
        // show a finished quiz that paid nothing.
        self::assertFalse($body['complete']);
    }

    /** A question whose correct answer is out of range is hidden rather than unanswerable. */
    public function testAMisconfiguredQuestionIsNotServed(): void
    {
        $this->seedQuestions(1);
        $this->account();

        $broken = $this->em->find(SetupQuizQuestion::class, $this->questionIds[0]);
        $broken->setCorrectIndex(99);
        $this->em->flush();

        $this->authedRequest('GET', '/api/setup-quiz');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['total']);
    }

    public function testTheQuizRequiresAuthentication(): void
    {
        $this->seedQuestions(1);

        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/api/setup-quiz');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // ───────────────────────── helpers ─────────────────────────

    /** Answers every question, getting exactly `$correct` of them right. */
    private function answerAll(int $correct, int $total): void
    {
        for ($i = 0; $i < $total; $i++) {
            // Option 0 is the correct one on every seeded question.
            $this->authedRequest(
                'POST',
                "/api/setup-quiz/{$this->questionIds[$i]}/answer",
                ['answer' => $i < $correct ? 0 : 1],
            );
            self::assertResponseIsSuccessful();
        }
    }

    private function seedQuestions(int $count): void
    {
        $this->questionIds = [];

        for ($i = 0; $i < $count; $i++) {
            // Option 0 is always correct, so a test's intent reads off the submitted index.
            $question = new SetupQuizQuestion(
                sprintf('Test question %d?', $i + 1),
                ['right', 'wrong', 'also wrong'],
                0,
            );
            $question->setSortOrder($i);
            $this->em->persist($question);
            $this->questionIds[] = (string) $question->getId();
        }

        $this->em->flush();
    }

    private function account(): void
    {
        $user = new User(self::EMAIL_PREFIX . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);
        $this->em->flush();

        $this->currentUserId = (string) $user->getId();

        // The grant the bonus is a multiple of. Written through the real service path so the
        // bonus is computed against the same figure a live account would have.
        self::getContainer()->get(UserLedgerService::class)->append(
            $user,
            null,
            UserLedgerEntryType::STARTING_GRANT,
            self::GRANT_PENCE,
            'test',
            'quiz-grant-' . bin2hex(random_bytes(5)),
        );
    }

    /**
     * Pin the grant range to a single figure and set the multiplier.
     *
     * The grant is normally a RANGE resolved per user id, so without pinning it the expected
     * bonus would differ per generated account and the assertions could not name a number.
     * `starter_config` is a singleton row that purge() does not touch, so tearDown() restores it.
     */
    private function pinGrantAndMultiplier(int $grantPence, float $multiplier): void
    {
        $this->connection->executeStatement(
            'UPDATE starter_config SET user_starting_balance_min_pence = :g, user_starting_balance_max_pence = :g, quiz_bonus_multiplier = :m',
            ['g' => $grantPence, 'm' => $multiplier],
        );
        $this->em->clear();
    }

    private function restoreConfig(): void
    {
        $this->connection->executeStatement(
            'UPDATE starter_config SET user_starting_balance_min_pence = 500000000, user_starting_balance_max_pence = 600000000, quiz_bonus_multiplier = 1.0',
        );
    }

    /** @param array<string, mixed> $body */
    private function authedRequest(string $method, string $uri, array $body = []): void
    {
        self::ensureKernelShutdown();

        $this->client = static::createClient();

        $c                = self::getContainer();
        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);

        $this->client->loginUser($this->em->find(User::class, $this->currentUserId), 'api');

        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body === [] ? '' : json_encode($body),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function purge(): void
    {
        $c    = $this->connection;
        $like = self::EMAIL_PREFIX . '%@test.local';

        $c->executeStatement('DELETE FROM setup_quiz_answer WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE ?)', [$like]);
        $c->executeStatement("DELETE FROM setup_quiz_answer WHERE question_id IN (SELECT id FROM setup_quiz_question WHERE prompt LIKE 'Test question%')");
        $c->executeStatement("DELETE FROM setup_quiz_question WHERE prompt LIKE 'Test question%'");
        $c->executeStatement('DELETE FROM user_ledger WHERE user_id IN (SELECT id FROM "user" WHERE email LIKE ?)', [$like]);
        $c->executeStatement('DELETE FROM "user" WHERE email LIKE ?', [$like]);
    }
}

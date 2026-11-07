<?php

declare(strict_types=1);

namespace App\Tests\Service\Setup;

use App\Entity\Setup\SetupQuizQuestion;
use App\Repository\Setup\SetupQuizQuestionRepository;
use App\Service\Setup\SetupQuizImportExportService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Moving the quiz, and authoring it from a file.
 *
 * The properties worth protecting are the ones that cost something when they break: a
 * re-import must UPDATE rather than duplicate (or an operator correcting a typo ends up with
 * two questions and a payout scored against both), it must never DELETE (answers cascade off a
 * question, so a delete silently destroys the one-attempt-per-account enforcement), and a
 * question with no reachable correct answer must be REFUSED rather than stored as something
 * nobody can get right.
 */
class SetupQuizImportExportServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private SetupQuizImportExportService $service;
    private SetupQuizQuestionRepository $questions;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->service    = self::getContainer()->get(SetupQuizImportExportService::class);
        $this->questions  = self::getContainer()->get(SetupQuizQuestionRepository::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    // ── Export ──────────────────────────────────────────────────────────────

    public function testExportCarriesEverythingNeededToRebuildAQuestion(): void
    {
        $question = $this->question('Who scouts?', ['Scout', 'Chef'], 0, sortOrder: 3);

        $data = $this->service->export();

        self::assertSame(1, $data['version']);
        self::assertCount(1, $data['questions']);

        $row = $data['questions'][0];
        self::assertSame((string) $question->getId(), $row['id']);
        self::assertSame('Who scouts?', $row['prompt']);
        self::assertSame(['Scout', 'Chef'], $row['options']);
        self::assertSame(3, $row['sortOrder']);
        self::assertTrue($row['isActive']);

        // The answer key. It has to travel, or the imported quiz pays everyone for question
        // one — which is also why the admin page says not to publish this file.
        self::assertSame(0, $row['correctIndex']);
    }

    public function testExportIncludesWithdrawnQuestions(): void
    {
        // isActive is authoring state, and a withdrawal is a decision. Dropping those rows
        // would mean the file could not reproduce the quiz it came from.
        $this->question('Live one', ['a', 'b'], 0);
        $this->question('Withdrawn one', ['a', 'b'], 1, active: false);

        $prompts = array_column($this->service->export()['questions'], 'prompt');

        self::assertContains('Withdrawn one', $prompts);
        self::assertCount(2, $prompts);
    }

    public function testExportNeverCarriesPlayerAnswers(): void
    {
        // Per-account game history. Importing it elsewhere would fabricate attempts nobody
        // made, against users who may not exist in that environment.
        $this->question('Anything', ['a', 'b'], 0);

        self::assertArrayNotHasKey('answers', $this->service->export());
        self::assertSame(['version', 'exportedAt', 'questions'], array_keys($this->service->export()));
    }

    // ── Round trip ──────────────────────────────────────────────────────────

    public function testAReimportUpdatesRatherThanDuplicating(): void
    {
        // The reason identity is the id and not the prompt: correcting a typo and re-importing
        // must be an edit, not a second question scored alongside the first.
        $question = $this->question('Wat does a scout do?', ['Finds players', 'Cuts grass'], 0);
        $id       = (string) $question->getId();

        $file = $this->service->export();
        $file['questions'][0]['prompt'] = 'What does a scout do?';

        $result = $this->service->import($file);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['updated']);
        self::assertCount(1, $this->questions->findAll(), 'still one question, not two');
        self::assertSame('What does a scout do?', $this->questions->find($id)->getPrompt());
    }

    public function testAnUnchangedFileReportsNoChanges(): void
    {
        $this->question('Stable', ['a', 'b'], 0);

        $result = $this->service->import($this->service->export());

        self::assertSame(0, $result['created']);
        self::assertSame(0, $result['updated']);
        self::assertSame(1, $result['unchanged']);
    }

    public function testImportingIntoAnEmptyDatabasePreservesTheIds(): void
    {
        // What makes two environments converge instead of drifting apart with every import —
        // and what keeps setup_quiz_answer.question_id pointing at the same question.
        $original = $this->question('Travelling', ['a', 'b'], 1);
        $id       = (string) $original->getId();
        $file     = $this->service->export();

        $this->purge();
        self::assertCount(0, $this->questions->findAll());

        $result = $this->service->import($file);

        self::assertSame(1, $result['created']);
        self::assertNotNull($this->questions->find($id), 'the id must survive the trip');
    }

    // ── Authoring from a hand-written file ──────────────────────────────────

    public function testAFileWithNoIdsImportsAsNewQuestions(): void
    {
        // The common case in practice: twenty questions are quicker to write as JSON than to
        // type into a form twenty times.
        $result = $this->service->import(['questions' => [
            ['prompt' => 'First?', 'options' => ['a', 'b'], 'correctIndex' => 0],
            ['prompt' => 'Second?', 'options' => ['x', 'y', 'z'], 'correctIndex' => 2],
        ]]);

        self::assertSame([], $result['errors']);
        self::assertSame(2, $result['created']);
        self::assertCount(2, $this->questions->findAll());
    }

    public function testAMinimalRowGetsSensibleDefaults(): void
    {
        $this->service->import(['questions' => [
            ['prompt' => 'Bare', 'options' => ['a', 'b'], 'correctIndex' => 1],
        ]]);

        $question = $this->questions->findAll()[0];

        self::assertTrue($question->isActive(), 'a new question is live unless the file says otherwise');
        self::assertSame(0, $question->getSortOrder());
        self::assertTrue($question->isAnswerable());
    }

    public function testAFileWithNoVersionIsAccepted(): void
    {
        // A hand-written file should not have to know about the export envelope.
        $result = $this->service->import(['questions' => [
            ['prompt' => 'No version', 'options' => ['a', 'b'], 'correctIndex' => 0],
        ]]);

        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['created']);
    }

    // ── Refusals ────────────────────────────────────────────────────────────

    public function testAQuestionWhoseCorrectAnswerDoesNotExistIsRefused(): void
    {
        // isAnswerable() would be false, so findServable() would hide it: a question invisible
        // in the API and inert in the admin. Better to say the file is wrong.
        $result = $this->service->import(['questions' => [
            ['prompt' => 'Out of range', 'options' => ['a', 'b'], 'correctIndex' => 5],
        ]]);

        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['created']);
        self::assertStringContainsString('correctIndex', $result['errors'][0]);
        self::assertCount(0, $this->questions->findAll());
    }

    public function testASingleOptionQuestionIsRefused(): void
    {
        $result = $this->service->import(['questions' => [
            ['prompt' => 'Only one', 'options' => ['a'], 'correctIndex' => 0],
        ]]);

        self::assertSame(1, $result['skipped']);
        self::assertStringContainsString('at least 2 options', $result['errors'][0]);
    }

    public function testAnEmptyPromptIsRefused(): void
    {
        $result = $this->service->import(['questions' => [
            ['prompt' => '   ', 'options' => ['a', 'b'], 'correctIndex' => 0],
        ]]);

        self::assertSame(1, $result['skipped']);
        self::assertStringContainsString('prompt', $result['errors'][0]);
    }

    public function testOneBadRowDoesNotStopTheGoodOnes(): void
    {
        // A twenty-question file with one typo should import nineteen and name the twentieth.
        $result = $this->service->import(['questions' => [
            ['prompt' => 'Good', 'options' => ['a', 'b'], 'correctIndex' => 0],
            ['prompt' => 'Bad', 'options' => ['a', 'b'], 'correctIndex' => 9],
            ['prompt' => 'Also good', 'options' => ['a', 'b'], 'correctIndex' => 1],
        ]]);

        self::assertSame(2, $result['created']);
        self::assertSame(1, $result['skipped']);
        self::assertStringContainsString('question 2', $result['errors'][0], 'the error must name which row');
    }

    public function testAFutureFileVersionIsRefusedOutright(): void
    {
        $result = $this->service->import(['version' => 99, 'questions' => [
            ['prompt' => 'From the future', 'options' => ['a', 'b'], 'correctIndex' => 0],
        ]]);

        self::assertSame(0, $result['created']);
        self::assertStringContainsString('version', $result['errors'][0]);
        self::assertCount(0, $this->questions->findAll());
    }

    public function testAFileWithNoQuestionsListIsRefused(): void
    {
        $result = $this->service->import(['nonsense' => true]);

        self::assertStringContainsString('questions', $result['errors'][0]);
    }

    public function testAnInvalidIdIsRefusedRatherThanSilentlyInserted(): void
    {
        $result = $this->service->import(['questions' => [
            ['id' => 'not-a-uuid', 'prompt' => 'Bad id', 'options' => ['a', 'b'], 'correctIndex' => 0],
        ]]);

        self::assertSame(1, $result['skipped']);
        self::assertStringContainsString('UUID', $result['errors'][0]);
    }

    // ── Never deletes ───────────────────────────────────────────────────────

    public function testAQuestionMissingFromTheFileIsLeftAlone(): void
    {
        // setup_quiz_answer cascades off question_id, so a delete here would not fail loudly —
        // it would silently take every player's answer with it.
        $keep = $this->question('Not in the file', ['a', 'b'], 0);

        $result = $this->service->import(['questions' => [
            ['prompt' => 'A new one', 'options' => ['a', 'b'], 'correctIndex' => 0],
        ]]);

        self::assertSame(0, $result['deactivated']);
        self::assertNotNull($this->questions->find((string) $keep->getId()));
        self::assertTrue($this->questions->find((string) $keep->getId())->isActive());
    }

    public function testDeactivateMissingWithdrawsRatherThanDeletes(): void
    {
        $orphan = $this->question('Superseded', ['a', 'b'], 0);
        $id     = (string) $orphan->getId();

        $result = $this->service->import(
            ['questions' => [['prompt' => 'The replacement', 'options' => ['a', 'b'], 'correctIndex' => 0]]],
            dryRun: false,
            deactivateMissing: true,
        );

        self::assertSame(1, $result['deactivated']);

        $still = $this->questions->find($id);
        self::assertNotNull($still, 'withdrawn, not deleted — answers reference this row');
        self::assertFalse($still->isActive());
    }

    public function testDeactivateMissingDoesNotRecountAnAlreadyWithdrawnQuestion(): void
    {
        $this->question('Already gone', ['a', 'b'], 0, active: false);

        $result = $this->service->import(['questions' => []], dryRun: false, deactivateMissing: true);

        self::assertSame(0, $result['deactivated']);
    }

    // ── Dry run ─────────────────────────────────────────────────────────────

    public function testADryRunReportsWithoutWriting(): void
    {
        $existing = $this->question('Existing', ['a', 'b'], 0);

        $result = $this->service->import(
            ['questions' => [
                ['id' => (string) $existing->getId(), 'prompt' => 'Edited', 'options' => ['a', 'b'], 'correctIndex' => 0],
                ['prompt' => 'Brand new', 'options' => ['a', 'b'], 'correctIndex' => 0],
            ]],
            dryRun: true,
        );

        self::assertTrue($result['dryRun']);
        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['updated']);

        $this->em->clear();
        self::assertCount(1, $this->questions->findAll(), 'nothing written');
        self::assertSame('Existing', $this->questions->findAll()[0]->getPrompt());
    }

    public function testADryRunDoesNotWithdrawAnything(): void
    {
        $orphan = $this->question('Would be withdrawn', ['a', 'b'], 0);

        $result = $this->service->import(['questions' => []], dryRun: true, deactivateMissing: true);

        self::assertSame(1, $result['deactivated'], 'reported');

        $this->em->clear();
        self::assertTrue($this->questions->find((string) $orphan->getId())->isActive(), 'but not applied');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @param list<string> $options */
    private function question(
        string $prompt,
        array $options,
        int $correctIndex,
        int $sortOrder = 0,
        bool $active = true,
    ): SetupQuizQuestion {
        $question = new SetupQuizQuestion($prompt, $options, $correctIndex);
        $question->setSortOrder($sortOrder);
        $question->setIsActive($active);

        $this->em->persist($question);
        $this->em->flush();

        return $question;
    }

    private function purge(): void
    {
        // Answers first: they reference questions, and although the FK cascades, leaning on a
        // cascade in a test would hide the day somebody changes it.
        $this->connection->executeStatement('DELETE FROM setup_quiz_answer');
        $this->connection->executeStatement('DELETE FROM setup_quiz_question');
        $this->em->clear();
    }
}

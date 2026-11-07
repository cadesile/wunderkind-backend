<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Setup\SetupQuizQuestion;
use App\Repository\Setup\SetupQuizQuestionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The quiz import/export screen and its two routes.
 *
 * The guards matter here for a specific reason: an import rewrites the questions that decide a
 * credit payout, and the export file is the answer key to it. So a tokenless POST must change
 * nothing, and neither write may be reachable by GET.
 */
class SetupQuizImportExportControllerTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'setup-quiz-ie-test@example.com';

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM setup_quiz_answer');
        $connection->executeStatement('DELETE FROM setup_quiz_question');

        foreach (glob(sys_get_temp_dir() . '/quiz-ie-*.json') ?: [] as $leftover) {
            @unlink($leftover);
        }

        parent::tearDown();
    }

    public function testTheScreenRenders(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin', ['routeName' => 'admin_setup_quiz_content']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/admin/setup-quiz/import"]');
        self::assertSelectorExists('input[name="quiz_file"]');

        // The two things that do not fit on a button, and are the reason this page exists.
        self::assertSelectorTextContains('body', 'answer key');
        self::assertSelectorTextContains('body', 'never deletes');
    }

    public function testTheExportDownloadsJsonCarryingTheAnswerKey(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $this->question('Who scouts?', ['Scout', 'Chef'], 1);

        $client->request('GET', '/admin/setup-quiz/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString(
            'attachment',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );

        $data = json_decode($this->streamedBody($client), true);

        self::assertSame(1, $data['version']);
        self::assertSame('Who scouts?', $data['questions'][0]['prompt']);
        self::assertSame(1, $data['questions'][0]['correctIndex']);
    }

    public function testImportingAFileCreatesTheQuestions(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/setup-quiz/import', [
            '_token' => $this->csrfToken($client, 'setup_quiz_import'),
        ], [
            'quiz_file' => $this->jsonUpload(['questions' => [
                ['prompt' => 'Imported?', 'options' => ['Yes', 'No'], 'correctIndex' => 0],
            ]]),
        ]);

        self::assertResponseRedirects();

        $questions = self::getContainer()->get(SetupQuizQuestionRepository::class)->findAll();
        self::assertCount(1, $questions);
        self::assertSame('Imported?', $questions[0]->getPrompt());
    }

    public function testADryRunImportWritesNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/setup-quiz/import', [
            '_token'  => $this->csrfToken($client, 'setup_quiz_import'),
            'dry_run' => '1',
        ], [
            'quiz_file' => $this->jsonUpload(['questions' => [
                ['prompt' => 'Would be added', 'options' => ['a', 'b'], 'correctIndex' => 0],
            ]]),
        ]);

        self::assertCount(0, self::getContainer()->get(SetupQuizQuestionRepository::class)->findAll());

        $client->followRedirect();
        // Flashes render as toasts, which upper-case the message.
        self::assertStringContainsStringIgnoringCase('dry run', (string) $client->getResponse()->getContent());
    }

    public function testImportingWithoutAValidTokenChangesNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/setup-quiz/import', ['_token' => 'nope'], [
            'quiz_file' => $this->jsonUpload(['questions' => [
                ['prompt' => 'Should not land', 'options' => ['a', 'b'], 'correctIndex' => 0],
            ]]),
        ]);

        self::assertCount(
            0,
            self::getContainer()->get(SetupQuizQuestionRepository::class)->findAll(),
            'a tokenless import must not rewrite the quiz',
        );
    }

    public function testImportingIsPostOnly(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/setup-quiz/import');

        self::assertResponseStatusCodeSame(405);
    }

    public function testAnUnparseableFileIsReportedNotSwallowed(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $path = tempnam(sys_get_temp_dir(), 'quiz-ie-') . '.json';
        file_put_contents($path, '<html>not json</html>');

        $client->request('POST', '/admin/setup-quiz/import', [
            '_token' => $this->csrfToken($client, 'setup_quiz_import'),
        ], [
            'quiz_file' => new UploadedFile($path, 'broken.json', 'application/json', null, true),
        ]);

        $client->followRedirect();
        self::assertStringContainsStringIgnoringCase('invalid json', (string) $client->getResponse()->getContent());
    }

    public function testTheQuizListLinksToTheScreen(): void
    {
        // The link belongs where someone authoring a quiz actually is, not only on the hub.
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/setup-quiz-question');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('admin_setup_quiz_content', (string) $client->getResponse()->getContent());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * The body of a StreamedResponse.
     *
     * Via getInternalResponse(), NOT getResponse(): a streamed body goes straight to output and
     * is never buffered, so getResponse()->getContent() is false. LocationAdminTest documents
     * the same trap for the location export.
     */
    private function streamedBody(KernelBrowser $client): string
    {
        return (string) $client->getInternalResponse()->getContent();
    }

    /** @param array<string, mixed> $payload */
    private function jsonUpload(array $payload): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'quiz-ie-') . '.json';
        file_put_contents($path, json_encode($payload));

        return new UploadedFile($path, 'quiz.json', 'application/json', null, true);
    }

    /** @param list<string> $options */
    private function question(string $prompt, array $options, int $correctIndex): SetupQuizQuestion
    {
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $question = new SetupQuizQuestion($prompt, $options, $correctIndex);

        $em->persist($question);
        $em->flush();

        return $question;
    }

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $client->disableReboot();

        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    /** Continues the logged-in session rather than starting a new one — see LocationAdminTest. */
    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $value   = 'test-csrf-token-' . bin2hex(random_bytes(8));
        $session = $client->getContainer()->get('session.factory')->createSession();

        $existing = $client->getCookieJar()->get($session->getName());

        if ($existing !== null) {
            $session->setId($existing->getValue());
        }

        $session->start();
        $session->set("_csrf/{$tokenId}", $value);
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $value;
    }
}

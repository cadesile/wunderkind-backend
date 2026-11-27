<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\GameConfig;
use App\Entity\PersonalAssistant;
use App\Entity\User;
use App\Enum\PersonalAssistantTone;
use App\Repository\GameConfigRepository;
use App\Service\PersonalAssistant\PaAdviceGeneratorInterface;
use App\Service\PersonalAssistant\PaAdviceUnavailableException;
use App\Tests\Support\FakePaAdviceGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261127100000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/pa/advice and the `personalAssistants` game-config key (2026-11-27).
 *
 * The AI generator is always the fake: no test may reach a real provider.
 */
final class PersonalAssistantControllerTest extends WebTestCase
{
    private const EMAIL_PREFIX = 'pa-advice-';
    private const SLUG_PREFIX  = 'test-pa-';
    private const URI          = '/api/pa/advice';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private FakePaAdviceGenerator $generator;
    private string $userId = '';
    private int $savedLimit = 20;

    protected function setUp(): void
    {
        $this->client    = static::createClient();
        $this->generator = new FakePaAdviceGenerator();
        $this->em        = self::getContainer()->get(EntityManagerInterface::class);

        $this->purge();
        $this->savedLimit = $this->config()->getPaAdviceDailyCallLimit();

        $user = new User(self::EMAIL_PREFIX . bin2hex(random_bytes(5)) . '@test.local');
        $user->setPassword('x');
        $this->em->persist($user);

        $active = new PersonalAssistant(self::SLUG_PREFIX . 'active', 'Test Active', 'male', 'he', 'seed-a', 'Shown in the picker.', 'SECRET-VOICE-BRIEF: talk like a pirate.', PersonalAssistantTone::Dry);
        $active->setOutOfOfficeMessage('Out to lunch.');
        $active->setSortOrder(-100);
        $this->em->persist($active);

        $inactive = new PersonalAssistant(self::SLUG_PREFIX . 'inactive', 'Test Inactive', 'female', 'she', 'seed-b', 'Hidden.', 'Hidden brief.', PersonalAssistantTone::Precise);
        $inactive->setIsActive(false);
        $this->em->persist($inactive);

        $this->em->flush();
        $this->userId = (string) $user->getId();
        $this->setLimit(20);
    }

    protected function tearDown(): void
    {
        $this->setLimit($this->savedLimit);
        $this->purge();
        parent::tearDown();
    }

    // ── Game config ───────────────────────────────────────────────────────

    public function testGameConfigServesActivePersonalitiesWithoutTheirPrompt(): void
    {
        $this->client->request('GET', '/api/game-config');
        self::assertResponseIsSuccessful();

        $roster = json_decode((string) $this->client->getResponse()->getContent(), true)['personalAssistants'] ?? null;
        self::assertIsArray($roster);

        $slugs = array_column($roster, 'slug');
        self::assertContains(self::SLUG_PREFIX . 'active', $slugs);
        self::assertNotContains(self::SLUG_PREFIX . 'inactive', $slugs);

        $mine = $roster[array_search(self::SLUG_PREFIX . 'active', $slugs, true)];
        self::assertSame(['id', 'slug', 'name', 'gender', 'pronoun', 'appearanceSeed', 'appearanceOverrides', 'description', 'tone', 'outOfOfficeMessage', 'sortOrder'], array_keys($mine));
        self::assertSame('dry', $mine['tone']);
        self::assertStringNotContainsString('SECRET-VOICE-BRIEF', (string) $this->client->getResponse()->getContent());
    }

    // ── Advice ────────────────────────────────────────────────────────────

    public function testAdviceIsWordedInThePersonalitysVoice(): void
    {
        $this->generator->reply = "  Boss, you're bleeding cash:\n six weeks of runway.  ";
        $this->advise();

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok', 'personalityId' => self::SLUG_PREFIX . 'active', 'message' => "Boss, you're bleeding cash: six weeks of runway."], $this->json());

        self::assertCount(1, $this->generator->calls);
        $call = $this->generator->calls[0];
        self::assertStringContainsString('SECRET-VOICE-BRIEF', $call['system'], 'the stored personality prompt is sent with the call');
        self::assertStringContainsString('Use only the facts and figures', $call['system']);
        self::assertStringContainsString('<club_data>', $call['user']);
        self::assertStringContainsString('FINANCIAL_BLEED', $call['user']);
        self::assertStringNotContainsString('SECRET-VOICE-BRIEF', $call['user']);
        self::assertSame(8, $call['timeout']);
    }

    public function testUnauthenticatedIsRefused(): void
    {
        $this->client->request('POST', self::URI, server: ['CONTENT_TYPE' => 'application/json'], content: $this->body());
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnInvalidTopicIs422(): void
    {
        $this->advise(['topic' => 'cheats']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_topic', $this->json()['error'] ?? null);
        self::assertSame([], $this->generator->calls);
    }

    public function testAnOversizedBodyIs413(): void
    {
        $this->advise(['padding' => str_repeat('x', 9000)]);
        self::assertResponseStatusCodeSame(413);
    }

    public function testUnknownAndInactivePersonalitiesAre404(): void
    {
        $this->advise(['personalityId' => self::SLUG_PREFIX . 'nobody']);
        self::assertResponseStatusCodeSame(404);

        $this->advise(['personalityId' => self::SLUG_PREFIX . 'inactive']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('unknown_personality', $this->json()['error'] ?? null);
    }

    public function testAnUnconfiguredProviderIsUnavailableAndCostsNothing(): void
    {
        $this->generator->configured = false;
        $this->setLimit(1);

        $this->advise();
        self::assertSame(['status' => 'unavailable', 'reason' => 'not_configured'], $this->json());

        $this->generator->configured = true;
        $this->advise();
        self::assertSame('ok', $this->json()['status'] ?? null, 'the not-configured call did not spend the one allowed call');
    }

    public function testAnUnsupportedLocaleIsUnavailable(): void
    {
        $this->advise(['locale' => 'es']);
        self::assertSame(['status' => 'unavailable', 'reason' => 'unsupported_locale'], $this->json());
        self::assertSame([], $this->generator->calls);
    }

    public function testProviderErrorsAndTimeoutsAreUnavailableNot500(): void
    {
        $this->generator->failWith = PaAdviceUnavailableException::PROVIDER_ERROR;
        $this->advise();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'unavailable', 'reason' => 'provider_error'], $this->json());

        $this->generator->failWith = PaAdviceUnavailableException::TIMEOUT;
        $this->advise();
        self::assertSame(['status' => 'unavailable', 'reason' => 'timeout'], $this->json());
    }

    public function testEmptyOrOverlongRepliesAreProviderErrors(): void
    {
        $this->generator->reply = '   ';
        $this->advise();
        self::assertSame(['status' => 'unavailable', 'reason' => 'provider_error'], $this->json());

        $this->generator->reply = str_repeat('a', 501);
        $this->advise();
        self::assertSame(['status' => 'unavailable', 'reason' => 'provider_error'], $this->json());
    }

    public function testTheDailyCapComesFromGameConfig(): void
    {
        $this->setLimit(2);

        $this->advise();
        self::assertSame('ok', $this->json()['status'] ?? null);
        $this->advise();
        self::assertSame('ok', $this->json()['status'] ?? null);

        $this->advise();
        $body = $this->json();
        self::assertSame('unavailable', $body['status'] ?? null);
        self::assertSame('rate_limited', $body['reason'] ?? null);
        self::assertGreaterThan(0, $body['retryAfterSeconds'] ?? 0);
        self::assertCount(2, $this->generator->calls, 'the capped call never reached the provider');

        // An admin raising the cap applies to the current window, with no deploy.
        $this->setLimit(3);
        $this->advise();
        self::assertSame('ok', $this->json()['status'] ?? null);
    }

    public function testAZeroCapTurnsAiWordingOff(): void
    {
        $this->setLimit(0);
        $this->advise();
        self::assertSame(['status' => 'unavailable', 'reason' => 'rate_limited'], $this->json());
        self::assertSame([], $this->generator->calls);
    }

    // ── Seed ──────────────────────────────────────────────────────────────

    public function testTheLaunchSeedIsIdempotentAndNeverOverwritesEdits(): void
    {
        // Migrations are not autoloaded (doctrine:migrations loads them by path).
        require_once \dirname(__DIR__, 3) . '/migrations/Version20261127100000.php';

        $conn  = self::getContainer()->get(Connection::class);
        $slugs = array_column(Version20261127100000::seedRows(), 'slug');
        $conn->executeStatement('DELETE FROM personal_assistant WHERE slug IN (?)', [$slugs], [\Doctrine\DBAL\ArrayParameterType::STRING]);

        try {
            $this->runSeedInserts($conn);
            $this->runSeedInserts($conn);

            $rows = $conn->fetchAllAssociative('SELECT slug, gender, personality_prompt FROM personal_assistant WHERE slug IN (?) ORDER BY sort_order', [$slugs], [\Doctrine\DBAL\ArrayParameterType::STRING]);
            self::assertCount(3, $rows);
            self::assertSame(['male', 'male', 'female'], array_column($rows, 'gender'));
            foreach ($rows as $row) {
                self::assertNotSame('', trim((string) $row['personality_prompt']));
            }

            $conn->executeStatement("UPDATE personal_assistant SET name = 'Edited by admin' WHERE slug = 'pa-marcus'");
            $this->runSeedInserts($conn);
            self::assertSame('Edited by admin', $conn->fetchOne("SELECT name FROM personal_assistant WHERE slug = 'pa-marcus'"));
        } finally {
            $conn->executeStatement('DELETE FROM personal_assistant WHERE slug IN (?)', [$slugs], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        }
    }

    private function runSeedInserts(Connection $conn): void
    {
        $migration = new Version20261127100000($conn, new NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());

        foreach ($migration->getSql() as $query) {
            if (str_contains($query->getStatement(), 'INSERT INTO personal_assistant')) {
                $conn->executeStatement($query->getStatement(), $query->getParameters());
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function body(array $overrides = []): string
    {
        return (string) json_encode($overrides + [
            'personalityId' => self::SLUG_PREFIX . 'active',
            'topic'         => 'finances',
            'locale'        => 'en',
            'context'       => ['clubName' => 'Harbour Town', 'tier' => 4, 'gameDate' => '2027-03-14', 'currency' => 'GBP'],
            'findings'      => [['code' => 'FINANCIAL_BLEED', 'severity' => 'high', 'facts' => ['weeksOfRunway' => 6, 'weeklyNet' => -4200000]]],
        ]);
    }

    /**
     * A fresh, authenticated client for ONE request (loginUser() authenticates a single request
     * against the stateless JWT firewall), with the fake generator put back each time.
     *
     * @param array<string, mixed> $overrides
     */
    private function advise(array $overrides = []): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        self::getContainer()->set(PaAdviceGeneratorInterface::class, $this->generator);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->client->loginUser($this->em->find(User::class, $this->userId), 'api');
        $this->client->request('POST', self::URI, server: ['CONTENT_TYPE' => 'application/json'], content: $this->body($overrides));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function config(): GameConfig
    {
        return self::getContainer()->get(GameConfigRepository::class)->getConfig(flush: true);
    }

    private function setLimit(int $limit): void
    {
        $conn = self::getContainer()->get(Connection::class);
        $this->config();
        $conn->executeStatement('UPDATE game_config SET pa_advice_daily_call_limit = ?', [$limit]);
    }

    private function purge(): void
    {
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement("DELETE FROM personal_assistant WHERE slug LIKE 'test-pa-%'");
        $conn->executeStatement('DELETE FROM "user" WHERE email LIKE ?', [self::EMAIL_PREFIX . '%']);
    }
}

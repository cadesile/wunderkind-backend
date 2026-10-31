<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Leaderboard\LeaderboardAwardDefinition;
use App\Entity\Leaderboard\LeaderboardAwardPlacement;
use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use App\Service\Leaderboard\LeaderboardPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The three admin surfaces: one full CRUD for config, two read-only screens for history.
 *
 * Row-click is asserted via role / data-default-action-url and then by following the URL —
 * never via the `ea-clickable-row` class, which EasyAdmin 5.x adds at runtime in JS that
 * DomCrawler never executes (see NotificationLogCrudControllerTest's docblock).
 */
class LeaderboardAwardAdminTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'leaderboard-award-test-admin@example.com';

    /** @var object[] */
    private array $cleanup = [];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    public function testDefinitionIndexIsFullCrud(): void
    {
        $client = $this->loggedInClient();
        $this->persistDefinition();
        $this->em->flush();

        $crawler = $client->request('GET', $this->url('admin_leaderboard_award_definition_index'));
        self::assertResponseIsSuccessful();

        $body = $crawler->filter('body')->html();
        self::assertStringContainsString('/new', $body, 'the definition CRUD must offer NEW — it is the thing admins configure');
    }

    public function testDefinitionNewAndEditPagesRender(): void
    {
        $client = $this->loggedInClient();
        $definition = $this->persistDefinition();
        $this->em->flush();

        $client->request('GET', $this->url('admin_leaderboard_award_definition_new'));
        self::assertResponseIsSuccessful();

        $client->request('GET', $this->url('admin_leaderboard_award_definition_edit', ['entityId' => (string) $definition->getId()]));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form', 'the placement CollectionField must render inside the definition form');
    }

    public function testRuntimeAnchorIsNotEditable(): void
    {
        $client = $this->loggedInClient();
        $definition = $this->persistDefinition();
        $this->em->flush();

        $crawler = $client->request('GET', $this->url('admin_leaderboard_award_definition_edit', ['entityId' => (string) $definition->getId()]));
        self::assertResponseIsSuccessful();

        // An editable monotonic anchor re-opens closed periods when moved backwards and
        // silently skips them when moved forwards, so it must not be on the form at all.
        self::assertSame(
            0,
            $crawler->filter('[name*="lastClosedPeriodKey"]')->count(),
            'lastClosedPeriodKey is runtime state and must never be an editable form field',
        );
        self::assertSame(0, $crawler->filter('[name*="lastClosedAt"]')->count());
    }

    public function testArchiveScreensAreReadOnly(): void
    {
        $client = $this->loggedInClient();
        $archive = $this->persistArchive();
        $this->persistArchiveEntry($archive);
        $this->em->flush();

        foreach (['admin_leaderboard_period_archive_index', 'admin_leaderboard_period_archive_entry_index'] as $route) {
            $crawler = $client->request('GET', $this->url($route));
            self::assertResponseIsSuccessful($route . ' must render');

            $body = $crawler->filter('body')->html();

            self::assertStringNotContainsString('action-new', $body, $route . ' must not offer NEW — an archive is immutable history');
            self::assertStringNotContainsString('action-edit', $body, $route . ' must not offer EDIT');
            self::assertStringNotContainsString('action-delete', $body, $route . ' must not offer DELETE');
        }
    }

    public function testArchiveRowClicksThroughToADetailPage(): void
    {
        $client = $this->loggedInClient();
        $archive = $this->persistArchive();
        $this->em->flush();

        $crawler = $client->request('GET', $this->url('admin_leaderboard_period_archive_index'));
        self::assertResponseIsSuccessful();

        $row = $crawler->filter(sprintf('tr[data-id="%s"]', $archive->getId()));
        self::assertGreaterThan(0, $row->count(), 'the archive row should be listed');
        self::assertSame('link', $row->attr('role'));

        $detailUrl = $row->attr('data-default-action-url');
        self::assertNotEmpty($detailUrl);

        $client->request('GET', $detailUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $archive->getPeriodKey());
    }

    public function testAwardScreenShowsTheFrozenRowAndItsEffects(): void
    {
        $client = $this->loggedInClient();
        $archive = $this->persistArchive();
        $entry   = $this->persistArchiveEntry($archive);
        $entry->markAwarded(1, [['type' => 'ledger_delta', 'amountPence' => 500_000]], null);
        $this->em->flush();

        $crawler = $client->request('GET', $this->url('admin_leaderboard_period_archive_entry_index'));
        self::assertResponseIsSuccessful();

        $row = $crawler->filter(sprintf('tr[data-id="%s"]', $entry->getId()));
        self::assertGreaterThan(0, $row->count());

        $client->request('GET', (string) $row->attr('data-default-action-url'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Archive Test FC');
        self::assertSelectorTextContains('body', 'ledger_delta', 'the frozen effects must be readable on the detail page');
    }

    // ── Close now ───────────────────────────────────────────────────────────────────────

    public function testTheDefinitionDetailPageOffersACloseNowButton(): void
    {
        $client     = $this->loggedInClient();
        $definition = $this->persistDefinition();
        $this->em->flush();

        $crawler = $client->request('GET', $this->url('admin_leaderboard_award_definition_detail', ['entityId' => (string) $definition->getId()]));
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('body', 'Close a period now');
        self::assertSelectorTextContains('body', 'Close now');

        $form = $crawler->filter('form[action*="close-now"]');
        self::assertGreaterThan(0, $form->count(), 'the close action must be a POST form, not a link');
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertGreaterThan(0, $form->filter('input[name="_token"]')->count(), 'and it must carry a CSRF token');
    }

    /**
     * Closing a period prunes live rows, pays prizes and sends inbox mail. A missing or
     * wrong token must change nothing.
     */
    public function testCloseNowRejectsAMissingCsrfToken(): void
    {
        $client     = $this->loggedInClient();
        $definition = $this->persistDefinition();
        $this->em->flush();

        $before = $this->archiveCount();

        $client->request('POST', '/admin/leaderboard-award/' . $definition->getId() . '/close-now', ['_token' => 'nonsense']);

        self::assertResponseRedirects();
        self::assertSame($before, $this->archiveCount(), 'a bad token must archive nothing');
    }

    public function testCloseNowIsPostOnly(): void
    {
        $client     = $this->loggedInClient();
        $definition = $this->persistDefinition();
        $this->em->flush();

        $client->request('GET', '/admin/leaderboard-award/' . $definition->getId() . '/close-now');

        self::assertSame(405, $client->getResponse()->getStatusCode(), 'a stray GET must not be able to close a period');
    }

    private function archiveCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM leaderboard_period_archive');
    }

    private function loggedInClient(): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $this->em->persist($admin);
            $this->em->flush();
        }

        $client->loginUser($admin, 'admin');

        return $client;
    }

    private function url(string $route, array $params = []): string
    {
        return self::getContainer()->get('router')->generate($route, $params);
    }

    private function persistDefinition(): LeaderboardAwardDefinition
    {
        $definition = new LeaderboardAwardDefinition();
        $definition
            ->setName('Admin Test Award')
            ->setCategory(LeaderboardCategory::EMPIRE_INDEX)
            ->setCadence(AwardCadence::QUARTERLY);

        $placement = new LeaderboardAwardPlacement(1);
        $placement->setPrizePence(500_000)->setReputationDelta(10);
        $definition->addPlacement($placement);

        return $this->persist($definition);
    }

    private function persistArchive(): LeaderboardPeriodArchive
    {
        $bounds = LeaderboardPeriod::boundsOf('2026-Q3');
        self::assertNotNull($bounds);

        $archive = new LeaderboardPeriodArchive(
            null,
            LeaderboardCategory::EMPIRE_INDEX,
            AwardCadence::QUARTERLY,
            'Admin Test Award',
            '2026-Q3',
            $bounds[0],
            $bounds[1],
            null,
            new \DateTimeImmutable(),
        );
        $archive->markArchived(1, 1, 1, new \DateTimeImmutable());

        return $this->persist($archive);
    }

    private function persistArchiveEntry(LeaderboardPeriodArchive $archive): LeaderboardPeriodArchiveEntry
    {
        return $this->persist(new LeaderboardPeriodArchiveEntry(
            $archive,
            1,
            'club-' . bin2hex(random_bytes(6)),
            'Archive Test FC',
            'user-' . bin2hex(random_bytes(6)),
            120,
            520,
            400,
            false,
            true,
            'Top Scorer',
        ));
    }

    /** @template T of object @param T $entity @return T */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->cleanup[] = $entity;

        return $entity;
    }
}

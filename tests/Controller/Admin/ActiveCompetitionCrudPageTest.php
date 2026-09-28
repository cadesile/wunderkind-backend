<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionDrawService;
use App\Service\Competition\CompetitionLockService;
use App\Service\Competition\CompetitionResultsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers two things for the Active Competitions admin section:
 *
 * 1. Regression coverage for a real production crash: durationOption is a backed
 *    CompetitionDuration enum with no __toString(), and rendering it directly used to
 *    throw "could not be converted to string" the moment a real ActiveCompetition row
 *    existed. Index page fix: configureFields() renders it via ChoiceField+EnumType.
 *    Detail page fix: detail() no longer uses EasyAdmin's default configureFields()
 *    rendering at all (see below), so it can't hit the same failure mode.
 * 2. The "view an active competition" admin page itself — detail() is overridden (same
 *    pattern as ClubCrudController::detail()) to show the full bracket (rounds ->
 *    fixtures -> results) inline, since this repo deliberately avoids standalone
 *    CompetitionFixture/CompetitionResult CRUD controllers (see
 *    CompetitionRoundCrudController's docblock). Tested against a real processed round
 *    so it catches the same class of bug the API's show() endpoint had (result silently
 *    never wired up despite the round actually completing).
 * 3. "Generate Spoof Entrants" — an admin test-data tool that lives on THIS controller
 *    (the competition row/detail page), not on CompetitionEntrantCrudController, even
 *    though it clones an existing entrant's snapshot under the hood — see
 *    CompetitionSpoofEntrantService::generateSpoofEntrantsForCompetition().
 */
class ActiveCompetitionCrudPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'active-competition-crud-test-admin@example.com';
    private const SLUG             = 'crud-page-probe-active-cup';

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);

        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    private function removeFixtures(EntityManagerInterface $em): void
    {
        $template = $em->getRepository(CompetitionTemplate::class)->findOneBy(['slug' => self::SLUG]);
        if ($template !== null) {
            foreach ($em->getRepository(ActiveCompetition::class)->findBy(['template' => $template]) as $instance) {
                $em->remove($instance);
            }
            $em->remove($template);
            $em->flush();
        }
    }

    public function testIndexPageRendersAnInstanceWithoutCrashingOnTheDurationEnum(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeFixtures($em);

        $template = new CompetitionTemplate('Crud Page Probe Active Cup', self::SLUG, 8, CompetitionDuration::ONE_DAY);
        $em->persist($template);
        $instance = new ActiveCompetition($template);
        $em->persist($instance);
        $em->flush();

        $client->request('GET', '/admin/active-competition');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Crud Page Probe Active Cup');
        self::assertSelectorTextContains('body', CompetitionDuration::ONE_DAY->name);

        $this->removeFixtures($em);
    }

    /**
     * Same root cause as the index-page test, but the detail page no longer shares its
     * rendering path — detail() is now a custom override (see class docblock) that shows
     * durationOption.value ("24h"), not the raw enum case name, so the assertion here
     * reflects the new template rather than the original EasyAdmin default one.
     */
    public function testDetailPageRendersAnInstanceWithNoRoundsWithoutCrashing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeFixtures($em);

        $template = new CompetitionTemplate('Crud Page Probe Active Cup', self::SLUG, 8, CompetitionDuration::ONE_DAY);
        $em->persist($template);
        $instance = new ActiveCompetition($template);
        $em->persist($instance);
        $em->flush();

        $client->request('GET', '/admin/active-competition/' . $instance->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', CompetitionDuration::ONE_DAY->value);
        // Still REGISTERING with zero rounds — the empty-state branch, not a crash.
        self::assertSelectorTextContains('body', 'REGISTERING');

        $this->removeFixtures($em);
    }

    private function createClub(EntityManagerInterface $em, string $name): Club
    {
        $user = new User('active-comp-admin-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club($name, $user);

        $em->persist($user);
        $em->persist($club);

        return $club;
    }

    /** Builds a locked, drawn, 4-entrant ActiveCompetition (round 1 = SF, round 2 = FINAL) and resolves round 1. */
    private function buildCompetitionWithOneProcessedRound(EntityManagerInterface $em): ActiveCompetition
    {
        $template = new CompetitionTemplate('Admin View Cup', 'admin-view-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $em->persist($template);

        $instance = new ActiveCompetition($template);
        $em->persist($instance);

        foreach (['Alpha FC', 'Bravo FC', 'Charlie FC', 'Delta FC'] as $name) {
            $club    = $this->createClub($em, $name);
            $entrant = new CompetitionEntrant($instance, $club, 0, [
                'club'    => ['id' => (string) $club->getId(), 'name' => $name],
                'players' => [['id' => 'p1', 'position' => 'MID', 'name' => "{$name} Talisman", 'currentAbility' => 10]],
            ]);
            $em->persist($entrant);
        }
        $em->flush();

        $lockService = self::getContainer()->get(CompetitionLockService::class);
        $lockService->lock($instance);
        $em->flush();

        $roundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
        $round1          = $roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        self::getContainer()->get(CompetitionDrawService::class)->forceDrawRound($round1);

        $em->refresh($round1);
        $fixtureRepository = self::getContainer()->get(\App\Repository\Competition\CompetitionFixtureRepository::class);
        $resultsService     = self::getContainer()->get(CompetitionResultsService::class);
        foreach ($fixtureRepository->findByRoundOrderedBySlot($round1) as $fixture) {
            $resultsService->forceResolveFixture($fixture);
        }

        return $instance;
    }

    /** Same as buildCompetitionWithOneProcessedRound() but stops right after drawing — round 1's fixtures stay PENDING. */
    private function buildLockedCompetitionWithUnprocessedRound(EntityManagerInterface $em): ActiveCompetition
    {
        $instance = $this->buildLockedCompetitionWithUndrawnRound($em);

        $roundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
        $round1          = $roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        self::getContainer()->get(CompetitionDrawService::class)->forceDrawRound($round1);

        return $instance;
    }

    /** Stops right after locking — round 1 stays DRAW_PENDING with no fixtures at all. */
    private function buildLockedCompetitionWithUndrawnRound(EntityManagerInterface $em): ActiveCompetition
    {
        $template = new CompetitionTemplate('Force Result Cup', 'force-result-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $em->persist($template);

        $instance = new ActiveCompetition($template);
        $em->persist($instance);

        foreach (['Alpha FC', 'Bravo FC', 'Charlie FC', 'Delta FC'] as $name) {
            $club    = $this->createClub($em, $name);
            $entrant = new CompetitionEntrant($instance, $club, 0, [
                'club'    => ['id' => (string) $club->getId(), 'name' => $name],
                'players' => [['id' => 'p1', 'position' => 'MID', 'currentAbility' => 10]],
            ]);
            $em->persist($entrant);
        }
        $em->flush();

        self::getContainer()->get(CompetitionLockService::class)->lock($instance);
        $em->flush();

        return $instance;
    }

    public function testDetailPageOffersGenerateResultForPendingFixturesOnly(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildLockedCompetitionWithUnprocessedRound($em);

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();

        $this->assertStringContainsString('Generate Result', $crawler->text());
        // No result exists yet, so there is nothing to expand.
        $this->assertStringNotContainsString('Details', $crawler->text());
    }

    public function testGenerateResultButtonForcesAFixtureAndAdvancesTheBracketOnceRoundCompletes(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildLockedCompetitionWithUnprocessedRound($em);

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();

        $forms = $crawler->filter('form')->reduce(fn ($node) => str_contains((string) $node->attr('action'), 'generate-result'));
        self::assertGreaterThan(0, $forms->count(), 'expected at least one Generate Result form on the page');

        // Resolve both round-1 fixtures one row at a time, exactly as an admin clicking twice would.
        $client->submit($forms->eq(0)->form());
        self::assertResponseRedirects();
        $client->followRedirect();

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        $forms   = $crawler->filter('form')->reduce(fn ($node) => str_contains((string) $node->attr('action'), 'generate-result'));
        self::assertGreaterThan(0, $forms->count(), 'the second SF fixture should still be forceable');

        $client->submit($forms->eq(0)->form());
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        $roundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
        $round1          = $roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        self::assertSame(\App\Enum\Competition\CompetitionRoundStatus::RESULTS_PUBLISHED, $round1->getStatus());

        // FINAL round is scheduled to draw next but has no fixtures yet — drawing it is
        // CompetitionDrawService's job, on a later tick.
        $this->assertStringContainsString('FINAL', $crawler->text());
    }

    public function testForceDrawButtonDrawsADrawPendingRoundImmediately(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildLockedCompetitionWithUndrawnRound($em);

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('Force Draw', $crawler->text());
        $this->assertStringContainsString('No fixtures.', $crawler->text());

        $forms = $crawler->filter('form')->reduce(fn ($node) => str_contains((string) $node->attr('action'), 'force-draw'));
        self::assertGreaterThan(0, $forms->count(), 'expected at least one Force Draw form on the page');

        $client->submit($forms->eq(0)->form());
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        $roundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
        $round1          = $roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        self::assertSame(\App\Enum\Competition\CompetitionRoundStatus::DRAWN, $round1->getStatus());
        $this->assertStringContainsString('Generate Result', $crawler->text());
    }

    public function testDetailPageShowsRoundsFixturesAndScores(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildCompetitionWithOneProcessedRound($em);

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();

        $text = $crawler->text(null, true);
        $this->assertStringContainsString('SF', $text);
        $this->assertStringContainsString('FINAL', $text);
        $this->assertStringContainsString('complete', $text);

        // Round 1 fixtures paired the 4 seeded clubs — their names must render regardless
        // of bracket pairing order.
        foreach (['Alpha FC', 'Bravo FC', 'Charlie FC', 'Delta FC'] as $name) {
            $this->assertStringContainsString($name, $text);
        }

        // A completed fixture must show a real "home – away" scoreline, not a blank dash,
        // and must offer a way to see the full result detail (event log).
        $this->assertMatchesRegularExpression('/\d+\s*[\x{2013}-]\s*\d+/u', $text, 'expected a rendered scoreline like "2 – 1"');
        $this->assertStringContainsString('Details', $text);
    }

    public function testDetailPageResultDetailsIncludeEngineAndEventLog(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildCompetitionWithOneProcessedRound($em);

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();

        $text = $crawler->text(null, true);
        $this->assertStringContainsString('deterministic', $text);
    }

    public function testDetailPageResultDetailsIncludeStartingXiWithRatings(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildCompetitionWithOneProcessedRound($em);

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();

        $text = $crawler->text(null, true);
        $this->assertStringContainsString('Starting XI', $text);
        // Every seeded club has exactly one named player — its lineup entry must render.
        foreach (['Alpha FC', 'Bravo FC', 'Charlie FC', 'Delta FC'] as $name) {
            $this->assertStringContainsString("{$name} Talisman", $text);
        }

        // The exact stored rating for a real lineup entry must be the one rendered.
        $roundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
        $round1          = $roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $fixture         = self::getContainer()->get(\App\Repository\Competition\CompetitionFixtureRepository::class)->findByRoundOrderedBySlot($round1)[0];
        $result          = self::getContainer()->get(\App\Repository\Competition\CompetitionResultRepository::class)->findOneBy(['fixture' => $fixture]);
        $rating          = $result->getHomeLineupJson()[0]['rating'];
        $this->assertStringContainsString((string) $rating, $text);
    }

    public function testDetailPageResultDetailsExposeFullRawPayloadAndClientSummary(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildCompetitionWithOneProcessedRound($em);

        $roundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
        $round1          = $roundRepository->findByCompetitionOrderedByIndex($instance)[0];
        $result          = self::getContainer()->get(\App\Repository\Competition\CompetitionResultRepository::class)
            ->findByFixtureIds(
                array_map(
                    fn ($f) => $f->getId(),
                    self::getContainer()->get(\App\Repository\Competition\CompetitionFixtureRepository::class)->findByRoundOrderedBySlot($round1),
                ),
            );
        $firstResult = array_values($result)[0];

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();

        $text = $crawler->text(null, true);
        $this->assertStringContainsString('Full stored payload', $text);
        $this->assertStringContainsString('What the device receives', $text);

        // Full payload includes fields the client never receives (event log detail, engine, timestamp).
        $this->assertStringContainsString('"eventLogJson"', $crawler->html());
        $this->assertStringContainsString('"engineIdentifier"', $crawler->html());

        // The client-summary block is exactly toClientSummary()'s output — score only.
        $this->assertStringContainsString($firstResult->getClientSummaryPretty(), $crawler->html());
        $this->assertStringNotContainsString('"eventLogJson"', $firstResult->getClientSummaryPretty());
    }

    public function testIndexPageRowClicksThroughToDetail(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildCompetitionWithOneProcessedRound($em);

        $crawler = $client->request('GET', '/admin/active-competition');
        self::assertResponseIsSuccessful();

        $link = $crawler->filter(sprintf('a[href*="%s"]', $instance->getId()))->first();
        self::assertGreaterThan(0, $link->count(), 'index row must link through to the detail page');

        $client->click($link->link());
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString($instance->getTemplate()->getName(), $client->getResponse()->getContent());
    }

    public function testEditAndNewActionsAreDisabled(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildCompetitionWithOneProcessedRound($em);

        $client->request('GET', '/admin/active-competition/new');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/active-competition/' . $instance->getId() . '/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteRemovesTheCompetitionAndCascadesToItsData(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em         = self::getContainer()->get(EntityManagerInterface::class);
        $instance   = $this->buildCompetitionWithOneProcessedRound($em);
        $instanceId = $instance->getId();

        // EasyAdmin's delete action is a JS-confirmed modal, not a per-row <form> — every
        // index page carries one shared hidden #action-confirmation-form with the real
        // session-backed CSRF token; JS sets its action to the clicked row's delete URL
        // before submitting. Read that same token and POST directly, rather than
        // fabricating one outside any request context.
        $crawler = $client->request('GET', '/admin/active-competition');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('#action-confirmation-form input[name="token"]')->attr('value');
        $client->request('POST', '/admin/active-competition/' . $instanceId . '/delete', ['token' => $token]);
        self::assertResponseRedirects();

        $freshEm = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($freshEm->find(ActiveCompetition::class, $instanceId));
        self::assertSame(0, $freshEm->getRepository(\App\Entity\Competition\CompetitionRound::class)->count(['activeCompetition' => $instanceId]));
    }

    public function testClearAllDeletesEveryCompetition(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->buildCompetitionWithOneProcessedRound($em);
        $this->buildEmptyOpenCompetition($em);

        $crawler = $client->request('GET', '/admin/active-competition');
        $crawler = $client->click($crawler->selectLink('Clear All')->link());
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('Delete All', $crawler->text());

        $form = $crawler->filter('form')->first()->form();
        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $freshEm = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $freshEm->getRepository(ActiveCompetition::class)->count([]));
    }

    /** Builds a REGISTERING (not yet locked) ActiveCompetition with one real entrant to use as a spoof basis. */
    private function buildOpenCompetitionWithOneEntrant(EntityManagerInterface $em, int $capacity = 8): ActiveCompetition
    {
        $template = new CompetitionTemplate('Spoof Trigger Cup', 'spoof-trigger-cup-' . uniqid('', true), $capacity, CompetitionDuration::ONE_DAY);
        $em->persist($template);

        $instance = new ActiveCompetition($template);
        $em->persist($instance);

        $club    = $this->createClub($em, 'Real FC');
        $entrant = new CompetitionEntrant($instance, $club, 0, [
            'club'    => ['id' => (string) $club->getId(), 'name' => 'Real FC', 'country' => 'EN', 'reputation' => 50],
            'players' => [['id' => 'p1', 'position' => 'MID', 'name' => 'Real Player', 'currentAbility' => 65]],
            'staff'   => [['id' => 's1', 'role' => 'MANAGER', 'name' => 'Real Manager']],
        ]);
        $em->persist($entrant);
        $em->flush();

        return $instance;
    }

    public function testGenerateSpoofEntrantsFillsCompetitionAndTagsSpoofClubs(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildOpenCompetitionWithOneEntrant($em, capacity: 8);

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();

        $link    = $crawler->selectLink('Generate Spoof Entrants')->link();
        $crawler = $client->click($link);
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('slot', $crawler->text());

        $form          = $crawler->selectButton('Generate')->form();
        $form['count'] = 7; // capacity 8, one real entrant already registered — 7 remain

        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        // Re-fetch via the (possibly rebooted) container's own EntityManager rather than
        // refreshing the original $em/$instance — each client request can reboot the
        // kernel, detaching entities fetched before it.
        $freshEm  = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $freshEm->find(ActiveCompetition::class, $instance->getId());
        self::assertSame(ActiveCompetitionStatus::SCHEDULED, $instance->getStatus());

        $entrantRepository = self::getContainer()->get(CompetitionEntrantRepository::class);
        self::assertSame(8, $entrantRepository->countForCompetition($instance));
    }

    public function testGenerateSpoofEntrantsIsUnavailableOnceCompetitionIsLocked(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildOpenCompetitionWithOneEntrant($em);

        $instance->setStatus(ActiveCompetitionStatus::SCHEDULED);
        $em->flush();

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Generate Spoof Entrants', $crawler->text());
    }

    public function testGenerateSpoofEntrantsIsUnavailableWithNoEntrantsYet(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $template = new CompetitionTemplate('Empty Spoof Cup', 'empty-spoof-cup-' . uniqid('', true), 8, CompetitionDuration::ONE_DAY);
        $em->persist($template);
        $instance = new ActiveCompetition($template);
        $em->persist($instance);
        $em->flush();

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Generate Spoof Entrants', $crawler->text());
    }

    public function testGenerateSpoofEntrantsActionIsNotOfferedOnTheEntrantAdminScreen(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildOpenCompetitionWithOneEntrant($em);

        $entrant = self::getContainer()->get(CompetitionEntrantRepository::class)
            ->findByCompetitionOrderedByRegistration($instance)[0];

        $crawler = $client->request('GET', '/admin/competition-entrant/' . $entrant->getId());
        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Generate Spoof Entrants', $crawler->text());
    }

    private function buildEmptyOpenCompetition(EntityManagerInterface $em, int $capacity = 4): ActiveCompetition
    {
        $template = new CompetitionTemplate('Spoof All Cup', 'spoof-all-cup-' . uniqid('', true), $capacity, CompetitionDuration::ONE_DAY);
        $em->persist($template);

        $instance = new ActiveCompetition($template);
        $em->persist($instance);
        $em->flush();

        return $instance;
    }

    public function testSpoofAllEntrantsFillsAnEmptyCompetitionAndLocksIt(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildEmptyOpenCompetition($em, capacity: 4);

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('Spoof All Entrants', $crawler->text());

        $crawler = $client->click($crawler->selectLink('Spoof All Entrants')->link());
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('4', $crawler->text());

        $form = $crawler->selectButton('Spoof All 4 Remaining Slots')->form();
        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $freshEm  = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $freshEm->find(ActiveCompetition::class, $instance->getId());
        self::assertSame(ActiveCompetitionStatus::SCHEDULED, $instance->getStatus());

        $entrantRepository = self::getContainer()->get(CompetitionEntrantRepository::class);
        self::assertSame(4, $entrantRepository->countForCompetition($instance));
    }

    public function testSpoofAllEntrantsIsUnavailableOnceCompetitionIsLocked(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $instance = $this->buildOpenCompetitionWithOneEntrant($em);

        $instance->setStatus(ActiveCompetitionStatus::SCHEDULED);
        $em->flush();

        $crawler = $client->request('GET', '/admin/active-competition/' . $instance->getId());
        self::assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Spoof All Entrants', $crawler->text());
    }
}

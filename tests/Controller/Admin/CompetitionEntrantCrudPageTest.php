<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The read-only "view club snapshots" admin — renders the index (summary columns derived
 * from snapshotJson) and detail (full pretty-printed JSON via CodeEditorField bound to the
 * virtual snapshotJsonPretty property) pages against a real persisted entrant, since this
 * repo has caught real EasyAdmin rendering bugs (missing __toString, missing constructor
 * defaults) only by actually loading the pages, not by code review alone.
 */
class CompetitionEntrantCrudPageTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'competition-entrant-crud-test-admin@example.com';

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

    private function seedEntrant(EntityManagerInterface $em): CompetitionEntrant
    {
        $template = new CompetitionTemplate('Snapshot Admin Cup', 'snapshot-admin-cup-' . uniqid('', true), 8, CompetitionDuration::ONE_DAY);
        $em->persist($template);

        $instance = new ActiveCompetition($template);
        $em->persist($instance);

        $user = new User('snapshot-admin-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $club = new Club('Snapshot FC', $user);
        $em->persist($user);
        $em->persist($club);

        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "p$i", 'position' => 'MID', 'currentAbility' => 65];
        }

        $entrant = new CompetitionEntrant($instance, $club, 1, [
            'club'    => ['id' => (string) $club->getId(), 'name' => 'Snapshot FC', 'formation' => '4-3-3', 'playingStyle' => 'HIGH_PRESS'],
            'players' => $players,
            'staff'   => [['id' => 's1', 'role' => 'MANAGER']],
        ]);
        $em->persist($entrant);
        $em->flush();

        return $entrant;
    }

    public function testIndexPageRendersWithSnapshotSummaryColumns(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedEntrant($em);

        $crawler = $client->request('GET', '/admin/competition-entrant');
        self::assertResponseIsSuccessful();

        $this->assertStringContainsString('Snapshot FC', $crawler->text());
        $this->assertStringContainsString('HIGH_PRESS', $crawler->text());
    }

    public function testDetailPageRendersFullSnapshotJson(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $entrant = $this->seedEntrant($em);

        $crawler = $client->request('GET', '/admin/competition-entrant/' . $entrant->getId() . '/edit');
        // EDIT is disabled entirely, but EasyAdmin still exposes the detail route.
        $crawler = $client->request('GET', '/admin/competition-entrant/' . $entrant->getId());
        self::assertResponseIsSuccessful();

        $this->assertStringContainsString('4-3-3', $crawler->text());
        $this->assertStringContainsString('"currentAbility": 65', $crawler->text());

        // ActiveCompetition::__toString() — without it, the "Competition" AssociationField
        // falls back to an opaque "ActiveCompetition #<uuid>" label instead of the real name.
        $this->assertStringContainsString('Snapshot Admin Cup', $crawler->text());
    }

    public function testEditNewAndDeleteActionsAreDisabled(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $entrant = $this->seedEntrant($em);

        $client->request('GET', '/admin/competition-entrant/new');
        self::assertResponseStatusCodeSame(403, 'NEW must be disabled — entrants are never hand-created.');

        $client->request('GET', '/admin/competition-entrant/' . $entrant->getId() . '/edit');
        self::assertResponseStatusCodeSame(403, 'EDIT must be disabled — snapshots are never hand-edited.');
    }

    private function buildOpenCompetition(EntityManagerInterface $em, int $capacity = 8): ActiveCompetition
    {
        $template = new CompetitionTemplate('Create Spoof Cup', 'create-spoof-cup-' . uniqid('', true), $capacity, CompetitionDuration::ONE_DAY);
        $em->persist($template);

        $instance = new ActiveCompetition($template);
        $em->persist($instance);
        $em->flush();

        return $instance;
    }

    /** @return array<string, mixed> A minimal but structurally-valid pasted snapshot. */
    private function pastedSnapshot(): array
    {
        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "pasted-p$i", 'position' => 'MID', 'name' => "Pasted Player $i", 'currentAbility' => 50];
        }

        return [
            'club'    => ['id' => 'client-side-id', 'name' => 'Oldham Warriors', 'reputation' => 100],
            'players' => $players,
            'staff'   => [['id' => 'pasted-s1', 'role' => 'MANAGER', 'name' => 'Klaus Braun']],
        ];
    }

    public function testCreateSpoofButtonIsOnTheIndexPage(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $crawler = $client->request('GET', '/admin/competition-entrant');
        self::assertResponseIsSuccessful();
        $this->assertNotNull($crawler->selectLink('Create Spoof')->link(), 'Create Spoof action must be on the index page.');
    }

    public function testCreateSpoofFormListsOpenCompetitionsAndRegistersASpoofEntrant(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em          = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $this->buildOpenCompetition($em);

        $crawler = $client->request('GET', '/admin/competition-entrant');
        $crawler = $client->click($crawler->selectLink('Create Spoof')->link());
        self::assertResponseIsSuccessful();
        $this->assertStringContainsString('Create Spoof Cup', $crawler->text());

        $form                              = $crawler->selectButton('Create Spoof Entrant')->form();
        $form['activeCompetitionId']       = (string) $competition->getId();
        $form['snapshot']                  = json_encode($this->pastedSnapshot(), JSON_THROW_ON_ERROR);
        // randomise left unchecked — verbatim registration.

        $client->submit($form);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->assertStringContainsString('Oldham Warriors', $crawler->text());
        $this->assertStringContainsString('"name": "Pasted Player 0"', $crawler->text());
    }

    public function testCreateSpoofFormShowsValidationErrorsAndPreservesPastedText(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em          = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $this->buildOpenCompetition($em);

        // Reached via the index page's link (which goes through /admin?routeName=... so the
        // `ea` Twig global gets populated), not the raw path directly — see
        // src/Controller/Admin/CLAUDE.md: a custom action rendering the EasyAdmin layout only
        // gets `ea` when the OUTER matched Symfony route is the dashboard's own `/admin` route.
        $indexCrawler = $client->request('GET', '/admin/competition-entrant');
        $crawler      = $client->click($indexCrawler->selectLink('Create Spoof')->link());
        self::assertResponseIsSuccessful();

        $malformedJson = '{"club": {"name": "Broken FC"';
        $form                        = $crawler->selectButton('Create Spoof Entrant')->form();
        $form['activeCompetitionId'] = (string) $competition->getId();
        $form['snapshot']            = $malformedJson;

        $crawler = $client->submit($form);
        self::assertResponseIsSuccessful('invalid input re-renders the form, not a redirect');
        $this->assertStringContainsString('not valid JSON', $crawler->text());
        $this->assertStringContainsString($malformedJson, $crawler->text(), 'pasted text must survive a validation error');
    }
}

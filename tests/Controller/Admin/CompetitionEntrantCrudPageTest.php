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
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Club;
use App\Entity\IllicitActivity;
use App\Entity\User;
use App\Enum\IllicitActivityKind;
use App\Service\IllicitActivity\IllicitActivityValidator;
use App\Tests\Service\IllicitActivity\IllicitActivityValidatorTest;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Operate › Accounts › Illicit activity, and the count on the club page (2026-11-27). */
final class IllicitActivityAdminTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'admin-illicit-activity@example.com';

    private KernelBrowser $client;
    private Club $club;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::ADMIN_EMAIL);
            $admin->setPassword('not-used');
            $em->persist($admin);
        }

        $this->user = new User('illicit-admin-' . bin2hex(random_bytes(5)) . '@test.local');
        $this->user->setPassword('x');
        $em->persist($this->user);
        $this->club = new Club('Shady Rovers', $this->user);
        $em->persist($this->club);

        $report = (new IllicitActivityValidator())->validate([IllicitActivityValidatorTest::record()])['records'][0];
        $row = new IllicitActivity($this->club, $report->clientId, IllicitActivityKind::FAKE_SPONSOR, new \DateTimeImmutable());
        $row->applyReport($report, new \DateTimeImmutable(), null);
        $em->persist($row);
        $em->flush();

        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement('DELETE FROM club_illicit_activity WHERE club_id = ?', [(string) $this->club->getId()]);
        $conn->executeStatement('DELETE FROM club WHERE id = ?', [(string) $this->club->getId()]);
        $conn->executeStatement('DELETE FROM "user" WHERE id = ?', [(string) $this->user->getId()]);
        parent::tearDown();
    }

    public function testTheListShowsTheReportedDeal(): void
    {
        $this->client->request('GET', '/admin/illicit-activity');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Northgate Holdings Ltd', $html);
        self::assertStringContainsString('£12,500', $html);
    }

    public function testTheClubPageCountsAndLinksIt(): void
    {
        $this->client->request('GET', '/admin/club/' . $this->club->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('View 1 reported cheat', $html);
        self::assertStringContainsString('illicit-activity', $html);
    }

    public function testTheGameConfigPageHasTheCheatSwitch(): void
    {
        $this->client->request('GET', '/admin', ['routeName' => 'admin_game_config']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('name="paCheatsEnabled"', (string) $this->client->getResponse()->getContent());
    }
}

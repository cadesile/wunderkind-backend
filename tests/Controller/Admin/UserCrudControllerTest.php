<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Club;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\UserLedgerEntryType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the "Clubs" / "Overall Balance" panel UserCrudController::edit() injects above the
 * standard edit form (see templates/admin/user_edit.html.twig) — club identity, last sync
 * (league position, form), per-club earnings/dividends, and the user's overall centralized
 * balance across every club they own.
 */
class UserCrudControllerTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'user-crud-test-admin@example.com';

    /** @var object[] */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $em->remove($managed);
            }
        }
        $this->cleanup = [];
        $em->flush();
        parent::tearDown();
    }

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    public function testEditPageShowsClubsSummaryAndOverallBalance(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(bin2hex(random_bytes(8)) . '@user-crud-test.test');
        $user->setPassword('x');
        $em->persist($user);
        $this->cleanup[] = $user;

        $club = new Club('Panel Test FC', $user);
        $club->setHomeKitConfig(['kit' => 'classic', 'primary' => '#ff0000', 'secondary' => '#ffffff', 'shorts' => '#ffffff', 'socks' => '#ff0000']);
        $club->setAwayKitConfig(['kit' => 'classic', 'primary' => '#000000', 'secondary' => '#ffffff', 'shorts' => '#000000', 'socks' => '#000000']);
        $club->setBadgeConfig(['badgeShape' => 'shield', 'badgePattern' => 'solid', 'badgeCentre' => 'star', 'initials' => 'PTF', 'badgeFill' => '#ff0000', 'badgeTrim' => '#ffffff', 'badgeSymbol' => 'star']);
        $club->setTotalCareerEarnings(500_000);
        $club->setLastSyncedWeek(12);
        $club->setLastSyncedAt(new \DateTimeImmutable());
        $em->persist($club);
        $this->cleanup[] = $club;
        $em->flush();

        $syncRecord = new SyncRecord($club, 12, new \DateTimeImmutable(), [
            'leaguePosition' => 2,
            'form'           => ['W', 'W', 'D', 'L', 'W'],
        ]);
        $em->persist($syncRecord);
        $this->cleanup[] = $syncRecord;

        $ledgerEntry = new UserLedger(
            $user,
            $club,
            UserLedgerEntryType::DIVIDEND_DRAW,
            25000,
            0,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            $syncRecord,
            0,
        );
        $em->persist($ledgerEntry);
        $this->cleanup[] = $ledgerEntry;
        $em->flush();

        $userId = (string) $user->getId();
        // User::$clubs is a plain ArrayCollection set in the constructor, not a lazy Doctrine
        // collection, for an entity that was `new`'d in this same PHP process rather than
        // hydrated from a query — without clearing the identity map, the controller's
        // getClubs() would return that same stale pre-persist instance (empty) instead of
        // triggering a fresh, real lazy load. A real HTTP request never hits this: it always
        // starts from a fresh EntityManager with nothing already in memory.
        $em->clear();

        $editUrl = self::getContainer()->get('router')->generate('admin_user_edit', ['entityId' => $userId]);
        $client->request('GET', $editUrl);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Panel Test FC');
        self::assertSelectorTextContains('body', 'Overall Balance');
        self::assertSelectorTextContains('body', '£250'); // overall balance: 25000 pence
        self::assertSelectorTextContains('body', 'Career Earnings');
        self::assertSelectorTextContains('body', 'Dividends Drawn');
        self::assertSelectorTextContains('body', 'P2'); // league position badge
        self::assertSelectorTextContains('body', 'Week 12');
    }

    public function testEditPageHandlesAUserWithNoClubs(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(bin2hex(random_bytes(8)) . '@user-crud-test.test');
        $user->setPassword('x');
        $em->persist($user);
        $this->cleanup[] = $user;
        $em->flush();

        $editUrl = self::getContainer()->get('router')->generate('admin_user_edit', ['entityId' => (string) $user->getId()]);
        $client->request('GET', $editUrl);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No clubs associated with this account.');
        self::assertSelectorTextContains('body', '£0');
    }
}

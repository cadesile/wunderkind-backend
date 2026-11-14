<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\Club;
use App\Entity\Club\ClubSale;
use App\Entity\User;
use App\Enum\Club\ClubSaleStatus;
use App\Message\Club\SellClubMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The operator's side of a buyout: the quote, the CSRF gate, and what the POST does.
 *
 * THE POST MUST ONLY QUEUE. The `clubsale` transport is in-memory in the test environment
 * precisely so these assertions are about the envelope and the row rather than about money
 * moving — a web request that both credited an account and deleted a club would have no safe
 * retry, and a browser will retry. So the strongest assertion here is that after a successful
 * POST the club is STILL THERE.
 */
class ClubSaleAdminTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'club-sale-test-admin@example.com';

    /** @var object[] */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        foreach (array_reverse($this->cleanup) as $entity) {
            $ids = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);

            if ($ids === []) {
                continue;
            }

            $managed = $em->find($entity::class, count($ids) === 1 ? reset($ids) : $ids);

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

    private function club(int $balance = 250_000): Club
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(bin2hex(random_bytes(8)) . '@club-sale.test');
        $user->setPassword('x');
        $em->persist($user);
        $this->cleanup[] = $user;

        $club = new Club('Quote Town FC', $user);
        $club->setBalance($balance);
        $club->setReputation(500);
        $em->persist($club);
        $this->cleanup[] = $club;

        $em->flush();

        return $club;
    }

    public function testTheQuoteEndpointReturnsTheFigureAndItsWorking(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $club = $this->club();

        $client->request('GET', '/admin/clubs/' . $club->getId() . '/sale-quote');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('Quote Town FC', $payload['clubName']);
        self::assertNotEmpty($payload['csrfToken']);
        self::assertNull($payload['existingSale']);

        // The whole working, not just the headline — the overlay renders it line by line so an
        // operator can account for the figure.
        self::assertSame(250_000, $payload['breakdown']['balancePence']);
        self::assertArrayHasKey('basis', $payload['breakdown']['land']);
        self::assertArrayHasKey('modelVersion', $payload['breakdown']);
        self::assertGreaterThan(250_000, $payload['breakdown']['estimatePence'], 'reputation and generosity apply');
    }

    public function testTheUserPageShowsAnEstimateAndASellControl(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $club = $this->club();

        $client->request('GET', '/admin/user/' . $club->getUser()->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-club-sale-trigger]');
        self::assertStringContainsString('Est. Value', (string) $client->getResponse()->getContent());
    }

    public function testABadCsrfTokenSellsNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $club = $this->club();

        $client->request('POST', '/admin/clubs/' . $club->getId() . '/sale', [
            '_token'       => 'not-the-token',
            'agreedAmount' => '1000',
        ]);

        self::assertResponseRedirects();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(ClubSale::class)->findOneBy(['clubId' => (string) $club->getId()]));
        self::assertNotNull($em->find(Club::class, (string) $club->getId()));
    }

    public function testAnUnreadableAmountIsRefusedRatherThanTreatedAsZero(): void
    {
        // MoneyInput returns null for blank AND for garbage, and the controller must not fall
        // back to 0 — a payout of nothing arrived at by mistyping is not one anyone authorised.
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $club = $this->club();

        $client->request('POST', '/admin/clubs/' . $club->getId() . '/sale', [
            '_token'       => $this->tokenFor($client, $club),
            'agreedAmount' => 'not a number',
        ]);

        self::assertResponseRedirects();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(ClubSale::class)->findOneBy(['clubId' => (string) $club->getId()]));
    }

    public function testAValidPostRecordsTheSaleQueuesItAndLeavesTheClubStanding(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $club = $this->club();

        $client->request('POST', '/admin/clubs/' . $club->getId() . '/sale', [
            '_token'       => $this->tokenFor($client, $club),
            // Commas and pence both, because the screen formats figures with separators and a
            // bare (int) cast on this used to store £10 for "1,000".
            'agreedAmount' => '1,234.56',
            'ledgerNote'   => 'Thanks for building Quote Town FC.',
        ]);

        self::assertResponseRedirects();

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $sale = $em->getRepository(ClubSale::class)->findOneBy(['clubId' => (string) $club->getId()]);
        $this->cleanup[] = $sale;

        self::assertNotNull($sale);
        self::assertSame(ClubSaleStatus::PENDING, $sale->getStatus());
        self::assertSame(123_456, $sale->getAgreedPence());
        self::assertSame('Thanks for building Quote Town FC.', $sale->getLedgerNote());
        self::assertSame(self::TEST_ADMIN_EMAIL, $sale->getAdminEmail(), 'the authorising operator is recorded');
        self::assertNotEmpty($sale->getWorkingJson(), 'the working is stored, recomputed server-side');

        // Queued, not done.
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.clubsale');
        $sent      = $transport->getSent();

        self::assertCount(1, $sent);
        self::assertInstanceOf(SellClubMessage::class, $sent[0]->getMessage());
        self::assertSame((string) $sale->getId(), $sent[0]->getMessage()->saleId);

        // THE POINT: no money has moved and the club is still here. The worker does that.
        self::assertNotNull($em->find(Club::class, (string) $club->getId()));
    }

    public function testASecondPostForTheSameClubIsRefused(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $club = $this->club();

        $client->request('POST', '/admin/clubs/' . $club->getId() . '/sale', [
            '_token'       => $this->tokenFor($client, $club),
            'agreedAmount' => '100',
        ]);

        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $sale = $em->getRepository(ClubSale::class)->findOneBy(['clubId' => (string) $club->getId()]);
        $this->cleanup[] = $sale;
        self::assertNotNull($sale);

        // A double-click, or a second tab. Pre-checked in the controller rather than left to the
        // partial unique index, because a flush violation closes the EntityManager and turns an
        // ordinary repeat into a 500.
        $client->request('POST', '/admin/clubs/' . $club->getId() . '/sale', [
            '_token'       => $this->tokenFor($client, $club),
            'agreedAmount' => '100',
        ]);

        self::assertResponseRedirects();
        self::assertCount(
            1,
            $em->getRepository(ClubSale::class)->findBy(['clubId' => (string) $club->getId()]),
        );
    }

    public function testAnAnonymousCallerCannotQuoteOrSell(): void
    {
        $client = static::createClient();
        $club   = $this->club();

        $client->request('GET', '/admin/clubs/' . $club->getId() . '/sale-quote');
        self::assertResponseStatusCodeSame(302, 'the admin firewall redirects to its login form');

        $client->request('POST', '/admin/clubs/' . $club->getId() . '/sale', ['agreedAmount' => '100']);
        self::assertResponseStatusCodeSame(302);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(ClubSale::class)->findOneBy(['clubId' => (string) $club->getId()]));
    }

    private function tokenFor(KernelBrowser $client, Club $club): string
    {
        $client->request('GET', '/admin/clubs/' . $club->getId() . '/sale-quote');

        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        return (string) $payload['csrfToken'];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The "Kill Session" control on /admin/user/{id} — end to end, including the
 * CSRF gate. The underlying revocation is covered by SessionRevocationServiceTest;
 * this is about the wiring actually being reachable from the page.
 */
class UserKillSessionTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'kill-session-test-admin@example.com';

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

    private function userWithSessions(int $count): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(bin2hex(random_bytes(8)) . '@kill-session.test');
        $user->setPassword('x');
        $em->persist($user);
        $this->cleanup[] = $user;

        for ($i = 0; $i < $count; $i++) {
            $token = new RefreshToken();
            $token->setRefreshToken(bin2hex(random_bytes(16)));
            $token->setUsername($user->getUserIdentifier());
            $token->setValid(new \DateTime('+30 days'));
            $em->persist($token);
            $this->cleanup[] = $token;
        }

        $em->flush();

        return $user;
    }

    public function testTheDetailPageOffersTheControlAndShowsTheSessionCount(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $user = $this->userWithSessions(2);

        $crawler = $client->request('GET', '/admin/user/' . $user->getId());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Kill Session', $client->getResponse()->getContent());
        $this->assertStringContainsString('2 active sessions', $client->getResponse()->getContent());
        $this->assertCount(
            1,
            $crawler->filter('form[action="/admin/users/' . $user->getId() . '/kill-session"]'),
            'The control must POST to the kill-session route.',
        );
    }

    public function testSubmittingTheFormRevokesEverything(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $user = $this->userWithSessions(3);

        $crawler = $client->request('GET', '/admin/user/' . $user->getId());
        $client->submitForm('Kill Session');

        $this->assertResponseRedirects();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(User::class, $user->getId());

        $this->assertSame(1, $reloaded->getTokenVersion());
        $this->assertSame(
            0,
            (int) $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM refresh_tokens WHERE username = :u',
                ['u' => $user->getUserIdentifier()],
            ),
        );
    }

    public function testAPostWithoutAValidCsrfTokenRevokesNothing(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $user = $this->userWithSessions(2);

        $client->request('POST', '/admin/users/' . $user->getId() . '/kill-session', ['_token' => 'nope']);

        $this->assertResponseRedirects();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(User::class, $user->getId());

        $this->assertSame(0, $reloaded->getTokenVersion(), 'A rejected CSRF must not bump the version.');
        $this->assertSame(
            2,
            (int) $em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM refresh_tokens WHERE username = :u',
                ['u' => $user->getUserIdentifier()],
            ),
        );
    }

    public function testTheRouteRejectsAnonymousCallers(): void
    {
        $client = static::createClient();
        $user   = $this->userWithSessions(1);

        $client->request('POST', '/admin/users/' . $user->getId() . '/kill-session', ['_token' => 'x']);

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/admin/login', (string) $client->getResponse()->headers->get('Location'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\UserLedgerEntryType;
use App\Repository\UserLedgerRepository;
use App\Service\StartingGrantService;
use App\Service\UserLedgerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Gift starter balance" on /admin/user/{id}: tops the starting grant up to the configured
 * maximum, at most once per account.
 */
class UserStarterGiftTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'starter-gift-test-admin@example.com';

    /** @var list<User> */
    private array $users = [];

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($this->users as $user) {
            $em->getConnection()->executeStatement('DELETE FROM user_ledger WHERE user_id = :u', ['u' => (string) $user->getId()]);
            $managed = $em->find(User::class, $user->getId());
            if ($managed !== null) {
                $em->remove($managed);
            }
        }
        $em->flush();
        $this->users = [];
        parent::tearDown();
    }

    private function login(KernelBrowser $client): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('x');
            $em->persist($admin);
            $em->flush();
        }
        $client->loginUser($admin, 'admin');
    }

    private function user(): User
    {
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(bin2hex(random_bytes(6)) . '@starter-gift.test');
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();
        $this->users[] = $user;

        return $user;
    }

    private function pressGift(KernelBrowser $client, User $user): void
    {
        $crawler = $client->request('GET', '/admin/user/' . $user->getId());
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/admin/users/' . $user->getId() . '/gift-starter-balance"]');
        $this->assertCount(1, $form, 'The gift button should be offered.');
        $client->request('POST', '/admin/users/' . $user->getId() . '/gift-starter-balance', [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
        ]);
        $this->assertResponseRedirects();
    }

    /** @return list<UserLedger> */
    private function grants(User $user): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(UserLedger::class)->findBy(['user' => $user->getId(), 'type' => UserLedgerEntryType::STARTING_GRANT]);
    }

    public function testAnAccountWithNoGrantGetsTheMaxAsItsGrantAndAvatarCreationAddsNothing(): void
    {
        $client = static::createClient();
        $this->login($client);
        $user = $this->user();
        $max  = self::getContainer()->get(StartingGrantService::class)->giftPreview($user)['maxPence'];
        $this->assertGreaterThan(0, $max);

        $this->pressGift($client, $user);

        $grants = $this->grants($user);
        $this->assertCount(1, $grants);
        $this->assertSame($max, $grants[0]->getAmountPence());
        $this->assertSame(StartingGrantService::SOURCE_KIND, $grants[0]->getSourceKind());

        // The avatar hook finds the gift under its own key and writes nothing more.
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId());
        self::getContainer()->get(StartingGrantService::class)->grantIfDue($reloaded);
        $this->assertCount(1, $this->grants($user));
        $this->assertSame($max, self::getContainer()->get(UserLedgerRepository::class)->getCurrentBalance($reloaded));
    }

    public function testASmallerGrantIsToppedUpToTheMaxOnceOnly(): void
    {
        $client = static::createClient();
        $this->login($client);
        $user = $this->user();
        $max  = self::getContainer()->get(StartingGrantService::class)->giftPreview($user)['maxPence'];

        self::getContainer()->get(UserLedgerService::class)->append(
            $user, null, UserLedgerEntryType::STARTING_GRANT, 100_00, StartingGrantService::SOURCE_KIND, (string) $user->getId(), 'Opening account credit',
        );

        $crawler = $client->request('GET', '/admin/user/' . $user->getId());
        $token   = $crawler->filter('form[action$="/gift-starter-balance"] input[name="_token"]')->attr('value');

        $this->pressGift($client, $user);

        $grants = $this->grants($user);
        $this->assertCount(2, $grants);
        $this->assertSame($max, array_sum(array_map(static fn (UserLedger $g) => $g->getAmountPence(), $grants)));

        // At the max now: the page shows a badge, not the button, and a re-post (stale page) pays nothing.
        $crawler = $client->request('GET', '/admin/user/' . $user->getId());
        $this->assertStringContainsString('Starter balance at max', $client->getResponse()->getContent());
        $this->assertCount(0, $crawler->filter('form[action$="/gift-starter-balance"]'));

        $client->request('POST', '/admin/users/' . $user->getId() . '/gift-starter-balance', ['_token' => $token]);
        $this->assertCount(2, $this->grants($user));
    }
}

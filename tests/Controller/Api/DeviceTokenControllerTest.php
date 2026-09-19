<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\User;
use App\Entity\UserDevice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DeviceTokenControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private mixed $currentUserId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement('TRUNCATE user_device CASCADE');
    }

    private function createClub(): Club
    {
        $user = new User('device-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $club = new Club('Device FC', $user);

        $this->em->persist($user);
        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function login(Club $club): void
    {
        $this->currentUserId = $club->getUser()->getId();
    }

    /** See AdminMessageControllerTest::authenticatedRequest() for why this reboots the kernel per call. */
    private function authenticatedRequest(string $method, string $uri, ?string $content = null): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->em->find(User::class, $this->currentUserId);
        $this->client->loginUser($user, 'api');

        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $content,
        );
    }

    /** @return array<string, mixed> */
    private function responseJson(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testUnauthenticatedRegisterIsRejected(): void
    {
        $this->client->request('POST', '/api/device-tokens', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['deviceToken' => 'x', 'platform' => 'ios']));
        $this->assertResponseStatusCodeSame(401);
    }

    public function testRegisterCreatesADevice(): void
    {
        $this->login($this->createClub());

        $this->authenticatedRequest('POST', '/api/device-tokens', json_encode(['deviceToken' => 'token-abc', 'platform' => 'ios', 'deviceId' => 'phone-1']));

        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($this->responseJson()['success']);

        $device = $this->em->getRepository(UserDevice::class)->findOneBy(['deviceToken' => 'token-abc']);
        $this->assertNotNull($device);
        $this->assertSame('phone-1', $device->getDeviceId());
        $this->assertTrue($device->getUser()->getId()->equals($this->currentUserId));
    }

    public function testReRegisteringTheSameTokenUnderADifferentUserReassignsIt(): void
    {
        $first  = $this->createClub();
        $second = $this->createClub();

        $this->login($first);
        $this->authenticatedRequest('POST', '/api/device-tokens', json_encode(['deviceToken' => 'shared-token', 'platform' => 'android']));
        $this->assertResponseStatusCodeSame(201);

        $this->login($second);
        $this->authenticatedRequest('POST', '/api/device-tokens', json_encode(['deviceToken' => 'shared-token', 'platform' => 'android']));
        $this->assertResponseStatusCodeSame(201);

        $devices = $this->em->getRepository(UserDevice::class)->findBy(['deviceToken' => 'shared-token']);
        $this->assertCount(1, $devices, 'Re-registering an existing token must reassign it, not duplicate it.');
        $this->assertTrue($devices[0]->getUser()->getId()->equals($second->getUser()->getId()));
    }

    public function testRegisterRejectsAMissingPlatform(): void
    {
        $this->login($this->createClub());

        $this->authenticatedRequest('POST', '/api/device-tokens', json_encode(['deviceToken' => 'token-no-platform']));

        $this->assertResponseStatusCodeSame(422);
    }

    public function testUnauthenticatedDeleteIsRejected(): void
    {
        $this->client->request('DELETE', '/api/device-tokens/some-token');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testDeleteRemovesTheAuthenticatedUsersDevice(): void
    {
        $club = $this->createClub();
        $this->login($club);
        $this->authenticatedRequest('POST', '/api/device-tokens', json_encode(['deviceToken' => 'to-delete', 'platform' => 'ios']));
        $this->assertResponseStatusCodeSame(201);

        $this->login($club);
        $this->authenticatedRequest('DELETE', '/api/device-tokens/to-delete');
        $this->assertResponseStatusCodeSame(200);

        $this->assertNull($this->em->getRepository(UserDevice::class)->findOneBy(['deviceToken' => 'to-delete']));
    }

    public function testDeleteOfAnotherUsersDeviceIsANoOp(): void
    {
        $owner  = $this->createClub();
        $other  = $this->createClub();

        $this->login($owner);
        $this->authenticatedRequest('POST', '/api/device-tokens', json_encode(['deviceToken' => 'owned-token', 'platform' => 'ios']));
        $this->assertResponseStatusCodeSame(201);

        $this->login($other);
        $this->authenticatedRequest('DELETE', '/api/device-tokens/owned-token');
        $this->assertResponseStatusCodeSame(200);

        $this->assertNotNull(
            $this->em->getRepository(UserDevice::class)->findOneBy(['deviceToken' => 'owned-token']),
            "One user's delete call must not remove another user's device.",
        );
    }
}

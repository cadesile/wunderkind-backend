<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ClubKitIdentityControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private mixed $currentUserId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUserWithClub(): void
    {
        $user = new User('kit-identity-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);

        $club = new Club('Kit Identity FC', $user);
        $this->em->persist($club);
        $this->em->flush();

        $this->currentUserId = $user->getId();
    }

    /** @see AdminMessageControllerTest for why this reboot dance is necessary. */
    private function authenticatedRequest(string $content, string $method = 'POST'): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->em->find(User::class, $this->currentUserId);
        $this->client->loginUser($user, 'api');

        $this->client->request(
            $method,
            '/api/club/kit-identity',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $method === 'GET' ? null : $content,
        );
    }

    public function testUnauthenticatedPostIsRejected(): void
    {
        $this->client->request('POST', '/api/club/kit-identity', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testUnauthenticatedGetIsRejected(): void
    {
        $this->client->request('GET', '/api/club/kit-identity');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testGetReturnsAllNullBeforeAnyCustomization(): void
    {
        $this->createUserWithClub();

        $this->authenticatedRequest('', 'GET');
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertNull($data['homeKitConfig']);
        $this->assertNull($data['awayKitConfig']);
        $this->assertNull($data['badgeConfig']);
    }

    public function testGetReturnsThePreviouslySetKitIdentity(): void
    {
        $this->createUserWithClub();

        $homeKit = ['kit' => 'stripes', 'primary' => '#c8202f', 'secondary' => '#f4f3ee', 'shorts' => 'black', 'socks' => 'primary'];

        $this->authenticatedRequest(json_encode(['homeKitConfig' => $homeKit]));
        $this->assertResponseStatusCodeSame(200);
        $posted = json_decode($this->client->getResponse()->getContent(), true);

        $this->authenticatedRequest('', 'GET');
        $this->assertResponseStatusCodeSame(200);
        $fetched = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame($posted, $fetched);
        $this->assertSame($homeKit, $fetched['homeKitConfig']);
    }

    public function testSettingHomeKitOnlyLeavesAwayKitAndBadgeUntouched(): void
    {
        $this->createUserWithClub();

        $homeKit = ['kit' => 'stripes', 'primary' => '#c8202f', 'secondary' => '#f4f3ee'];
        $this->authenticatedRequest(json_encode(['homeKitConfig' => $homeKit]));
        $this->assertResponseStatusCodeSame(200);

        $awayKit = ['kit' => 'hoops', 'primary' => '#000000', 'secondary' => '#ffffff'];
        $this->authenticatedRequest(json_encode(['awayKitConfig' => $awayKit]));
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame($homeKit, $data['homeKitConfig'], 'setting awayKitConfig must not touch homeKitConfig');
        $this->assertSame($awayKit, $data['awayKitConfig']);
        $this->assertNull($data['badgeConfig']);
    }

    public function testClientSuppliedConfigsAreStoredVerbatim(): void
    {
        $this->createUserWithClub();

        $badge = [
            'badgeShape' => 'shield', 'badgePattern' => 'stripes', 'badgeCentre' => 'initials',
            'initials' => 'TSD', 'badgeFill' => '#c8202f', 'badgeTrim' => '#f4f3ee', 'badgeSymbol' => '#f2c230',
        ];

        $this->authenticatedRequest(json_encode(['badgeConfig' => $badge]));
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame($badge, $data['badgeConfig']);
    }

    public function testNoClubReturns404(): void
    {
        $user = new User('no-club-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);
        $this->em->flush();
        $this->currentUserId = $user->getId();

        $this->authenticatedRequest('', 'GET');
        $this->assertResponseStatusCodeSame(404);
    }
}

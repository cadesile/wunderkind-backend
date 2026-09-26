<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OwnerAvatarControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private mixed $currentUserId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(): User
    {
        $user = new User('owner-avatar-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);

        $this->em->persist($user);
        $this->em->flush();

        $this->currentUserId = $user->getId();

        return $user;
    }

    /** @see AdminMessageControllerTest for why this reboot dance is necessary. */
    private function authenticatedRequest(string $content): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->em->find(User::class, $this->currentUserId);
        $this->client->loginUser($user, 'api');

        $this->client->request(
            'POST',
            '/api/owner-avatar',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $content,
        );
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $this->client->request('POST', '/api/owner-avatar', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testSettingNameOnlyLeavesOtherFieldsUntouched(): void
    {
        $this->createUser();

        $this->authenticatedRequest(json_encode(['name' => 'Alex Owner']));
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Alex Owner', $data['name']);
        $this->assertNull($data['nationality']);
        $this->assertNull($data['gender']);
        $this->assertNull($data['dob']);
        // First fill: appearance is still null before this call, so it should get generated.
        $this->assertNotNull($data['avatar']);

        // A second call touching only nationality/dob shouldn't reset name or regenerate a
        // fresh avatar over one that already exists.
        $this->authenticatedRequest(json_encode(['nationality' => 'English', 'dob' => '1990-05-14']));
        $this->assertResponseStatusCodeSame(200);
        $data2 = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Alex Owner', $data2['name']);
        $this->assertSame('English', $data2['nationality']);
        $this->assertSame('1990-05-14', $data2['dob']);
        $this->assertSame($data['avatar'], $data2['avatar'], 'avatar should not silently regenerate once it already exists');
    }

    public function testClientSuppliedAvatarIsStoredVerbatim(): void
    {
        $this->createUser();

        $customAvatar = [
            'hair' => 'bun', 'hairColor' => '#e6bd55', 'headband' => true, 'skin' => 's2',
            'face' => 'cool', 'facial' => 'none', 'lip' => '#c9575e',
            'primary' => '#1f4fb8', 'secondary' => '#f4f3ee',
            'kit' => 'stripes', 'shorts' => 'black', 'socks' => 'primary',
            'outfit' => 'suit', 'trousers' => 'black', 'glasses' => true,
        ];

        $this->authenticatedRequest(json_encode(['avatar' => $customAvatar]));
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($customAvatar, $data['avatar']);
    }

    public function testInvalidGenderIsRejected(): void
    {
        $this->createUser();

        $this->authenticatedRequest(json_encode(['gender' => 'unknown']));
        $this->assertResponseStatusCodeSame(422);
    }
}

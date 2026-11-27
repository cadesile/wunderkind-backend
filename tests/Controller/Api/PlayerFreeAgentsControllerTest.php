<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/players/free-agents — the season transition's free transfer top-up.
 *
 * Generates and never persists, so the only rows this test leaves behind would be its user,
 * which tearDown removes.
 */
class PlayerFreeAgentsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private mixed $userId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('free-agents-' . uniqid() . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);
        $this->em->flush();
        $this->userId = $user->getId();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        static::createClient();
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $this->userId);
        if ($user !== null) {
            $em->remove($user);
            $em->flush();
        }
        parent::tearDown();
    }

    public function testRequiresACountry(): void
    {
        $this->request('/api/players/free-agents');
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testRejectsAnUnknownCountry(): void
    {
        $this->request('/api/players/free-agents?country=ZZ');
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testExplicitCountIsHonouredAndEveryPlayerIsNativeAndUnpriced(): void
    {
        $body = $this->get('/api/players/free-agents?country=EN&count=12');

        $this->assertSame('EN', $body['country']);
        $this->assertSame(12, $body['amount']);
        $this->assertCount(12, $body['players']);
        foreach ($body['players'] as $p) {
            $this->assertSame('English', $p['nationality']);
            $this->assertArrayNotHasKey('contractValue', $p, 'packs carry no money');
            $this->assertIsInt($p['packTier']);
        }
    }

    public function testExtraIsAddedToTheConfiguredDefault(): void
    {
        $default = self::getContainer()->get(StarterConfigRepository::class)
            ->getConfig()->getFreeAgentSeasonTopUpCount();

        $body = $this->get('/api/players/free-agents?country=EN&extra=7');

        $this->assertSame(min(200, $default + 7), $body['amount']);
    }

    public function testCountIsClampedTo200(): void
    {
        $body = $this->get('/api/players/free-agents?country=EN&count=5000');
        $this->assertSame(200, $body['amount']);
    }

    private function get(string $uri): array
    {
        $this->request($uri);
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent() ?: '');

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** One authenticated request; loginUser() covers exactly one request on the JWT firewall. */
    private function request(string $uri): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->client->loginUser($this->em->find(User::class, $this->userId), 'api');
        $this->client->request('GET', $uri);
    }
}

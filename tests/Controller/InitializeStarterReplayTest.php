<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Club;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/initialize/starter must answer a retry, not reject it.
 *
 * The starter squad is drawn from the Player/Staff pool, snapshotted, and the pool rows are
 * then deleted (see Pool Lifecycle in CLAUDE.md), so the HTTP response was the only copy
 * that ever existed. A response lost in transit — Sentry `155675759`'s native crash during
 * "Assembling your squad…", a cancelled request, a backgrounded app — left the club
 * initialized with a squad it had never received, and every retry answered
 * 409 "Starter already initialized."
 */
class InitializeStarterReplayTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    public function testAnAlreadyInitializedClubIsServedItsStoredPack(): void
    {
        $pack = [
            'players' => [['id' => 'player-1', 'firstName' => 'Stored']],
            'staff'   => [['id' => 'staff-1']],
            'scouts'  => [['id' => 'scout-1']],
        ];

        $club = $this->initializedClub($pack);

        $this->postStarter($club);

        $this->assertResponseStatusCodeSame(200);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame($pack, $body['ampStarter'], 'the stored pack must come back verbatim');
        $this->assertTrue($body['replayed'], 'a replay says so, so the client can tell it apart from a fresh draw');
    }

    /**
     * Clubs initialized before the pack was stored really have nothing left to return —
     * their Player/Staff rows were deleted on consume — so those keep the 409. It carries a
     * machine-readable slug now rather than a bare sentence.
     */
    public function testAClubWithNoStoredPackStillConflicts(): void
    {
        $club = $this->initializedClub(null);

        $this->postStarter($club);

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame(
            'starter_already_initialized',
            json_decode((string) $this->client->getResponse()->getContent(), true)['error'],
        );
    }

    /**
     * The replay is checked ahead of the `?country=` override on purpose: the stored pack was
     * drawn for the country the club had at the time, and a retry carrying a different one
     * must not rewrite the club out from under it.
     */
    public function testARetryCarryingADifferentCountryDoesNotRewriteTheClub(): void
    {
        $pack = ['players' => [['id' => 'p']], 'staff' => [], 'scouts' => []];
        $club = $this->initializedClub($pack, country: 'EN');

        $this->postStarter($club, query: '?country=JP');

        $this->assertResponseStatusCodeSame(200);

        $this->em->clear();
        $reloaded = $this->em->find(Club::class, $club->getId());

        $this->assertSame('EN', $reloaded->getCountry(), 'an initialized club keeps the country its squad was drawn for');
    }

    /** @param array{players: array, staff: array, scouts: array}|null $pack */
    private function initializedClub(?array $pack, string $country = 'EN'): Club
    {
        $user = new User('starter-replay-' . bin2hex(random_bytes(6)) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);
        $this->em->persist($user);
        $this->cleanup[] = $user;

        $club = new Club('Starter Replay FC', $user);
        $club->setCountry($country);
        $club->setStarterInitializedAt(new \DateTimeImmutable());
        $club->setStarterPackJson($pack);
        $this->em->persist($club);
        $this->cleanup[] = $club;

        $this->em->flush();

        return $club;
    }

    private function postStarter(Club $club, string $query = ''): void
    {
        // Single-use login against the stateless JWT firewall — see CLAUDE.md.
        $userId = $club->getUser()->getId();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->client->loginUser($this->em->find(User::class, $userId), 'api');
        $this->client->request('POST', '/api/initialize/starter' . $query);
    }
}

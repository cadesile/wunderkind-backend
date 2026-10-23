<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\Friendly\FriendlyChallenge;
use App\Entity\User;
use App\Service\Friendly\FriendlyChallengeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FriendlyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private mixed $currentUserId = null;
    private mixed $currentClubId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE friendly_challenge, idempotency_record CASCADE',
        );
    }

    private function createClub(string $name, int $reputation = 25, int $balance = 500_000, ?User $owner = null): Club
    {
        if ($owner === null) {
            $owner = new User('friendly-' . uniqid('', true) . '@example.com');
            $owner->setPassword('x');
            $owner->setRoles([User::ROLE_CLUB]);
            $this->em->persist($owner);
        } else {
            $owner = $this->em->find(User::class, $owner->getId());
        }

        $club = new Club($name, $owner);
        $club->setReputation($reputation);
        $club->setBalance($balance);
        $club->setAbbreviation('FRD');
        $club->setCountry('EN');

        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function login(Club $club): void
    {
        $this->currentUserId = $club->getUser()->getId();
        $this->currentClubId = $club->getId();
    }

    private function authenticatedRequest(string $method, string $uri, ?string $content = null): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->em->find(User::class, $this->currentUserId);
        $this->client->loginUser($user, 'api');

        $server = [
            'CONTENT_TYPE'   => 'application/json',
            'HTTP_X_CLUB_ID' => (string) $this->currentClubId,
        ];

        $this->client->request($method, $uri, server: $server, content: $content);
    }

    /** @return array<string, mixed> */
    private function validSnapshotPayload(string $clubId, string $clubName, int $ability = 60): array
    {
        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "player-$i", 'position' => 'MID', 'currentAbility' => $ability];
        }

        return [
            'club'       => ['id' => $clubId, 'name' => $clubName],
            'players'    => $players,
            'staff'      => [],
            'facilities' => [],
        ];
    }

    public function testCreateListFilterAndCancelFriendlyChallengeEscrowsAndRefundsStake(): void
    {
        $challenger = $this->createClub('Challenger FC', 25, 200_000); // 25 reputation => regional tier (15..39)
        $viewer     = $this->createClub('Viewer United', 10, 200_000);

        $this->login($challenger);
        $this->authenticatedRequest(
            'POST',
            '/api/friendlies/challenges',
            json_encode([
                'stakePence'     => 50_000,
                'venue'          => 'home',
                'expiresInHours' => 6,
                'snapshot'       => $this->validSnapshotPayload((string) $challenger->getId(), $challenger->getName()),
            ]),
        );

        $this->assertResponseStatusCodeSame(201);
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $challengeId = $created['challenge']['challengeId'];
        $this->assertSame('home', $created['challenge']['venue']);
        $this->assertSame(50_000, $created['challenge']['stakePence']);
        $this->assertSame('regional', $created['challenge']['challenger']['reputationTier']);

        // Verify stake was escrowed
        $reloadedChallenger = $this->em->getRepository(Club::class)->find($challenger->getId());
        $this->assertSame(150_000, $reloadedChallenger->getBalance());

        // Viewer lists open challenges with stake=low and reputation=regional
        $this->login($viewer);
        $this->authenticatedRequest('GET', '/api/friendlies/challenges?stake=low&reputation=regional');
        $this->assertResponseIsSuccessful();
        $list = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $list['challenges']);
        $this->assertSame('away', $list['challenges'][0]['viewerVenue']);

        // Filtering by reputation=elite excludes it
        $this->authenticatedRequest('GET', '/api/friendlies/challenges?reputation=elite');
        $emptyList = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(0, $emptyList['challenges']);

        // Challenger cancels challenge -> stake refunded
        $this->login($challenger);
        $this->authenticatedRequest('POST', '/api/friendlies/challenges/' . $challengeId . '/cancel');
        $this->assertResponseIsSuccessful();
        $cancelRes = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('cancelled', $cancelRes['status']);
        $this->assertSame(200_000, $cancelRes['club']['balance']);
    }

    public function testAcceptAndResolveFriendlyChallenge(): void
    {
        $challenger    = $this->createClub('Home Stars', 25, 100_000);
        $sameOwnerClub = $this->createClub('Alt Save FC', 25, 100_000, $challenger->getUser());
        $acceptor      = $this->createClub('Away Wanderers', 25, 100_000);

        $this->login($challenger);
        $this->authenticatedRequest(
            'POST',
            '/api/friendlies/challenges',
            json_encode([
                'stakePence'     => 25_000,
                'venue'          => 'away',
                'expiresInHours' => 1,
                'snapshot'       => $this->validSnapshotPayload((string) $challenger->getId(), $challenger->getName(), 80),
            ]),
        );
        $this->assertResponseStatusCodeSame(201);
        $challengeId = json_decode((string) $this->client->getResponse()->getContent(), true)['challenge']['challengeId'];

        // Own challenge -> 409 own_challenge
        $this->authenticatedRequest(
            'POST',
            '/api/friendlies/challenges/' . $challengeId . '/accept',
            json_encode([
                'snapshot' => $this->validSnapshotPayload((string) $challenger->getId(), $challenger->getName()),
            ]),
        );
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('own_challenge', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);

        // Different club owned by same user -> 403 same_owner
        $this->login($sameOwnerClub);
        $this->authenticatedRequest(
            'POST',
            '/api/friendlies/challenges/' . $challengeId . '/accept',
            json_encode([
                'snapshot' => $this->validSnapshotPayload((string) $sameOwnerClub->getId(), $sameOwnerClub->getName()),
            ]),
        );
        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('same_owner', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);

        // Valid accept by another user's club; since challenger picked 'away', acceptor is 'home'
        $this->login($acceptor);
        $this->authenticatedRequest(
            'POST',
            '/api/friendlies/challenges/' . $challengeId . '/accept',
            json_encode([
                'snapshot' => $this->validSnapshotPayload((string) $acceptor->getId(), $acceptor->getName(), 40),
            ]),
        );
        $this->assertResponseIsSuccessful();
        $acceptData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame($challengeId, $acceptData['match']['matchId']);
        $this->assertSame('friendly', $acceptData['match']['kind']);
        $this->assertSame('scheduled', $acceptData['match']['status']);
        $this->assertSame((string) $acceptor->getId(), $acceptData['match']['home']['clubId']);
        $this->assertSame((string) $challenger->getId(), $acceptData['match']['away']['clubId']);

        // Backdate kickoffAt so resolveDueMatches resolves it now
        /** @var FriendlyChallenge $entity */
        $entity = $this->em->getRepository(FriendlyChallenge::class)->find($challengeId);
        $entity->setKickoffAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $resolved = self::getContainer()->get(FriendlyChallengeService::class)->resolveDueMatches(new \DateTimeImmutable());
        $this->assertSame(1, $resolved);

        // Fetch GET /api/matches/{challengeId} to verify complete match detail
        $this->authenticatedRequest('GET', '/api/matches/' . $challengeId);
        $this->assertResponseIsSuccessful();
        $matchDetail = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('complete', $matchDetail['status']);
        $this->assertNotNull($matchDetail['score']);
        $this->assertNotEmpty($matchDetail['homeLineup']);
        $this->assertNotEmpty($matchDetail['awayLineup']);
        $this->assertIsArray($matchDetail['narrativePayload']);
    }
}

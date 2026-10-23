<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionRound;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Service\Competition\CompetitionDrawService;
use App\Service\Competition\CompetitionResultsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MatchControllerTest extends WebTestCase
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
            'TRUNCATE competition_entrant, competition_fixture, competition_result,
                      competition_round, active_competition, competition_template_reward_template,
                      entrant_reward_claim, competition_template, reward_template, friendly_challenge CASCADE',
        );
    }

    private function createClub(string $name, int $reputation = 50, int $balance = 1_000_000): Club
    {
        $user = new User('match-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);

        $club = new Club($name, $user);
        $club->setReputation($reputation);
        $club->setBalance($balance);
        $club->setAbbreviation('MTC');
        $club->setCountry('EN');

        $this->em->persist($user);
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
    private function validSnapshotPayload(string $clubId, string $clubName, int $ability = 50): array
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

    public function testUpcomingMatchesShowsAwaitingEntrantsAwaitingDrawAndScheduledStates(): void
    {
        $template = new CompetitionTemplate('Test Cup', 'test-cup-' . uniqid('', true), 4, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);

        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);
        $this->em->flush();
        $instanceId = (string) $instance->getId();

        $club1 = $this->createClub('Club One');
        $club1Id = (string) $club1->getId();
        $this->login($club1);

        // 1. Register club1 -> competition still registering (1/4) -> state = awaiting_entrants
        $this->authenticatedRequest(
            'POST',
            '/api/competitions/' . $instanceId . '/register',
            json_encode($this->validSnapshotPayload($club1Id, $club1->getName())),
        );
        $this->assertResponseStatusCodeSame(201);

        $this->authenticatedRequest('GET', '/api/club/matches?state=upcoming');
        $this->assertResponseIsSuccessful();
        $upcoming1 = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $upcoming1['matches']);
        $this->assertSame('awaiting_entrants', $upcoming1['matches'][0]['status']);
        $this->assertSame(1, $upcoming1['matches'][0]['competition']['registeredCount']);
        $this->assertSame(4, $upcoming1['matches'][0]['competition']['entrantCapacity']);

        // 2. Fill remaining 3 slots -> locks competition into 'scheduled' with Round 1 draw_pending -> state = awaiting_draw
        for ($i = 2; $i <= 4; $i++) {
            $c = $this->createClub("Club $i");
            $this->login($c);
            $this->authenticatedRequest(
                'POST',
                '/api/competitions/' . $instanceId . '/register',
                json_encode($this->validSnapshotPayload((string) $c->getId(), $c->getName())),
            );
            $this->assertResponseStatusCodeSame(201);
        }

        $this->login($club1);
        $this->authenticatedRequest('GET', '/api/club/matches?state=upcoming');
        $this->assertResponseIsSuccessful();
        $upcoming2 = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $upcoming2['matches']);
        $this->assertSame('awaiting_draw', $upcoming2['matches'][0]['status']);
        $this->assertNotNull($upcoming2['matches'][0]['drawAt']);

        // 3. Draw Round 1 -> state = scheduled with fixture matchId
        $reloadedInstance = $this->em->getRepository(ActiveCompetition::class)->find($instanceId);
        $round1 = $this->em->getRepository(CompetitionRound::class)->findByCompetitionOrderedByIndex($reloadedInstance)[0];
        $round1->setScheduledAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();
        self::getContainer()->get(CompetitionDrawService::class)->drawDueRounds(new \DateTimeImmutable());

        $this->authenticatedRequest('GET', '/api/club/matches?state=upcoming');
        $this->assertResponseIsSuccessful();
        $upcoming3 = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $upcoming3['matches']);
        $this->assertSame('scheduled', $upcoming3['matches'][0]['status']);
        $matchId = $upcoming3['matches'][0]['matchId'];
        $this->assertNotNull($matchId);

        // 4. Fetch GET /api/matches/{matchId} before completion
        $this->authenticatedRequest('GET', '/api/matches/' . $matchId);
        $this->assertResponseIsSuccessful();
        $matchDetail = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame($matchId, $matchDetail['matchId']);
        $this->assertSame('cup', $matchDetail['kind']);
        $this->assertSame('scheduled', $matchDetail['status']);

        // 5. Resolve Round 1 -> check recent matches & H2H
        $reloadedInstance = $this->em->getRepository(ActiveCompetition::class)->find($instanceId);
        $round1 = $this->em->getRepository(CompetitionRound::class)->findByCompetitionOrderedByIndex($reloadedInstance)[0];
        $round1->setMatchesResolveAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();
        self::getContainer()->get(CompetitionResultsService::class)->publishDueResults(new \DateTimeImmutable());

        $this->authenticatedRequest('GET', '/api/club/matches?state=recent');
        $this->assertResponseIsSuccessful();
        $recent = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $recent['matches']);
        $this->assertSame('complete', $recent['matches'][0]['status']);
        $this->assertNotNull($recent['matches'][0]['score']);

        $opponentClubId = $recent['matches'][0]['home']['clubId'] === $club1Id
            ? $recent['matches'][0]['away']['clubId']
            : $recent['matches'][0]['home']['clubId'];

        // 6. H2H endpoint
        $this->authenticatedRequest('GET', '/api/club/h2h/' . $opponentClubId);
        $this->assertResponseIsSuccessful();
        $h2h = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $h2h['won'] + $h2h['drawn'] + $h2h['lost']);
        $this->assertCount(1, $h2h['recent']);
    }
}

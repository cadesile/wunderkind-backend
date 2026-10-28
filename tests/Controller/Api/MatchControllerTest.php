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
    private function validSnapshotPayload(string $clubId, string $clubName, int $ability = 50, bool $includeOptionalVenueAndStyle = true): array
    {
        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = [
                'id'             => "player-$i",
                'position'       => 'MID',
                'name'           => "$clubName Player $i",
                'currentAbility' => $ability,
                'appearance'     => [
                    'hair'      => 'curly',
                    'hairColor' => '#4a2c17',
                    'headband'  => true,
                    'skin'      => '#f1c27d',
                    'face'      => 'square',
                    'facial'    => 'stubble',
                    'lip'       => '#c57b6a',
                ],
            ];
        }

        $clubPayload = [
            'id'            => $clubId,
            'name'          => $clubName,
            'homePrimary'   => '#c8202f',
            'homeSecondary' => '#f4f3ee',
            'awayPrimary'   => '#f4f3ee',
            'awaySecondary' => '#c8202f',
            'badgeShape'    => 'shield',
            'homeKitStyle'  => 'j4',
            'awayKitStyle'  => 'j4',
            'kitHomeConfig' => ['kit' => 'stripes', 'shorts' => 'black', 'socks' => 'primary'],
            'kitAwayConfig' => ['kit' => 'plain', 'shorts' => 'white', 'socks' => 'secondary'],
            'badgeConfig'   => [
                'badgeShape'   => 'crest',
                'badgePattern' => 'halves',
                'badgeCentre'  => 'initials',
                'initials'     => 'CLB',
                'badgeFill'    => '#c8202f',
                'badgeTrim'    => '#f4f3ee',
                'badgeSymbol'  => '#f2c230',
            ],
            'identity'      => [
                'home' => [
                    'kit'       => 'stripes',
                    'primary'   => '#c8202f',
                    'secondary' => '#f4f3ee',
                    'shorts'    => 'black',
                    'socks'     => 'primary',
                ],
                'away' => [
                    'kit'       => 'plain',
                    'primary'   => '#f4f3ee',
                    'secondary' => '#c8202f',
                    'shorts'    => 'white',
                    'socks'     => 'secondary',
                ],
                'badgeShape'   => 'crest',
                'badgePattern' => 'halves',
                'badgeCentre'  => 'initials',
                'initials'     => 'CLB',
                'badgeFill'    => '#c8202f',
                'badgeTrim'    => '#f4f3ee',
                'badgeSymbol'  => '#f2c230',
            ],
            'stadium'       => [
                'north'             => 4,
                'east'              => 3,
                'south'             => 2,
                'west'              => 3,
                'northConstruction' => false,
                'eastConstruction'  => false,
                'southConstruction' => true,
                'westConstruction'  => false,
                'seat'              => '#c8202f',
                'roof'              => '#f4f3ee',
                'floodlights'       => true,
                'scoreboard'        => true,
                'shop'              => true,
                'museum'            => false,
                'carpark'           => true,
                'trees'             => true,
                'shopLevel'         => 2,
                'museumLevel'       => 1,
                'carparkLevel'      => 1,
                'home'              => 2,
                'away'              => 1,
                'pitch'             => 'stripes',
                'base'              => 'concrete',
            ],
        ];

        if ($includeOptionalVenueAndStyle) {
            $clubPayload['stadiumName']  = "$clubName Ground";
            $clubPayload['playingStyle'] = 'POSSESSION';
        }

        return [
            'club'       => $clubPayload,
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

        // 2. Fill remaining 3 slots (omitting stadiumName and playingStyle to test omitted-not-null contract) -> locks competition into 'scheduled' with Round 1 draw_pending -> state = awaiting_draw
        for ($i = 2; $i <= 4; $i++) {
            $c = $this->createClub("Club $i");
            $this->login($c);
            $this->authenticatedRequest(
                'POST',
                '/api/competitions/' . $instanceId . '/register',
                json_encode($this->validSnapshotPayload((string) $c->getId(), $c->getName(), 50, false)),
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
        $this->assertNull($matchDetail['score']);

        // An unplayed fixture answers who each club is fielding, from the registered
        // snapshot XIs, rather than two empty arrays — same shape as a played lineup with
        // the per-match values empty. Both sides, for both clubs.
        foreach (['homeLineup', 'awayLineup'] as $side) {
            $this->assertCount(11, $matchDetail[$side], "{$side} should carry the registered XI before kickoff");

            $first = $matchDetail[$side][0];
            $this->assertSame(
                ['name', 'position', 'appearance', 'goals', 'assists', 'yellowCards', 'redCards', 'rating'],
                array_keys($first),
                "{$side} entries must match the played-lineup shape exactly",
            );
            $this->assertNull($first['rating'], 'no rating exists before kickoff; 0 would read as the worst performance');
            $this->assertSame(0, $first['goals']);
            $this->assertSame(0, $first['assists']);
            $this->assertSame(0, $first['yellowCards']);
            $this->assertSame(0, $first['redCards']);
            $this->assertIsString($first['name']);
            $this->assertIsString($first['position']);
            // Appearance rides along so the pre-match screen can draw the players.
            $this->assertSame('curly', $first['appearance']['hair'] ?? null);
        }

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

        // 7. Verify completed match detail & competition result return identity, homeKitConfig,
        //    awayKitConfig, badgeConfig (with crest, overriding placeholder shield), stadium,
        //    contractual reputation/tier/badgeShape, explicit playingStyle, omitted-not-null stadiumName/playingStyle,
        //    and lineup appearance.
        $this->authenticatedRequest('GET', '/api/matches/' . $matchId);
        $this->assertResponseIsSuccessful();
        $completedMatch = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('complete', $completedMatch['status']);
        $this->assertSame('stripes', $completedMatch['home']['homeKitConfig']['kit'] ?? null);
        $this->assertSame('#c8202f', $completedMatch['home']['homeKitConfig']['primary'] ?? null);
        $this->assertSame('plain', $completedMatch['home']['awayKitConfig']['kit'] ?? null);
        $this->assertSame('#f4f3ee', $completedMatch['home']['awayKitConfig']['primary'] ?? null);
        $this->assertSame('crest', $completedMatch['home']['badgeConfig']['badgeShape'] ?? null);
        $this->assertSame('crest', $completedMatch['home']['badgeShape'] ?? null);
        $this->assertSame('crest', $completedMatch['home']['identity']['badgeShape'] ?? null);
        $this->assertSame(4, $completedMatch['home']['stadium']['north'] ?? null);
        $this->assertIsInt($completedMatch['home']['reputation']);
        $this->assertIsString($completedMatch['home']['tier']);
        $this->assertIsInt($completedMatch['away']['reputation']);
        $this->assertIsString($completedMatch['away']['tier']);
        $this->assertSame('crest', $completedMatch['away']['badgeShape'] ?? null);

        $club1Side = $completedMatch['home']['clubId'] === $club1Id ? $completedMatch['home'] : $completedMatch['away'];
        $otherSide = $completedMatch['home']['clubId'] === $club1Id ? $completedMatch['away'] : $completedMatch['home'];
        $this->assertSame('Club One Ground', $club1Side['stadiumName'] ?? null);
        $this->assertSame('POSSESSION', $club1Side['playingStyle'] ?? null);
        $this->assertArrayNotHasKey('stadiumName', $otherSide);
        $this->assertArrayNotHasKey('playingStyle', $otherSide);

        $this->assertCount(11, $completedMatch['homeLineup']);
        $this->assertSame('curly', $completedMatch['homeLineup'][0]['appearance']['hair'] ?? null);

        // The bracket is checked here for what it does NOT carry. Every club field asserted
        // above used to be cross-checked against the bracket's per-fixture `result` block,
        // because the same payload existed in both places and could drift. The bracket no
        // longer embeds match detail — it carries the scoreline and a `matchId` — so there is
        // nothing left to keep in sync, and the assertions above already cover the shape at
        // the endpoint that serves it.
        $this->authenticatedRequest('GET', '/api/competitions/' . $instanceId);
        $this->assertResponseIsSuccessful();
        $compDetail   = json_decode((string) $this->client->getResponse()->getContent(), true);
        $firstFixture = $compDetail['rounds'][0]['fixtures'][0];

        $this->assertArrayNotHasKey('result', $firstFixture, 'the bracket must not re-embed match detail');
        $this->assertNotNull($firstFixture['matchId'], 'the bracket must hand out the handle to fetch detail with');
        $this->assertIsInt($firstFixture['score']['home'], 'the scoreline stays in the bracket so it renders unaided');
    }
}

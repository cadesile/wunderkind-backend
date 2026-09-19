<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CompetitionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private mixed $currentUserId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_fixture, competition_result,
                      competition_round, active_competition, competition_template_reward_template,
                      entrant_reward_claim, competition_template, reward_template CASCADE',
        );
    }

    private function createClub(string $name, int $reputation = 0): Club
    {
        $user = new User('cup-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);

        $club = new Club($name, $user);
        $club->setReputation($reputation);

        $this->em->persist($user);
        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function createTemplate(int $capacity = 4, int $minReputation = 0): CompetitionTemplate
    {
        $template = new CompetitionTemplate('Test Cup', 'test-cup-' . uniqid('', true), $capacity, CompetitionDuration::TEN_HOURS);
        $template->setMinClubReputation($minReputation);

        $this->em->persist($template);
        $this->em->flush();

        return $template;
    }

    private function createOpenInstance(CompetitionTemplate $template): ActiveCompetition
    {
        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);
        $this->em->flush();

        return $instance;
    }

    /** @return array<string, mixed> A minimal but structurally valid snapshot envelope. */
    private function validSnapshotPayload(string $clubId, string $clubName): array
    {
        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = ['id' => "player-$i", 'position' => 'MID', 'currentAbility' => 10];
        }

        return [
            'club'       => ['id' => $clubId, 'name' => $clubName],
            'players'    => $players,
            'staff'      => [],
            'facilities' => [],
        ];
    }

    /**
     * The 2026 client expansion (see SnapshotValidator's class doc comment) — full club/
     * players/staff shape including every new field from the handoff, to prove they
     * persist verbatim end-to-end and don't trip validation.
     *
     * @return array<string, mixed>
     */
    private function snapshotPayloadWithNewFields(string $clubId, string $clubName): array
    {
        $players = [];
        for ($i = 0; $i < 11; $i++) {
            $players[] = [
                'id'             => "player-$i",
                'position'       => 'MID',
                'currentAbility' => 10,
                'name'           => "Player $i",
                'dateOfBirth'    => '2010-01-01',
                'age'            => 16,
                'nationality'    => 'Testland',
                'potential'      => 80,
                'personality'    => [
                    'determination' => 15, 'professionalism' => 10, 'ambition' => 12,
                    'loyalty' => 8, 'adaptability' => 14, 'pressure' => 9,
                    'temperament' => 11, 'consistency' => 13,
                ],
                'morale'         => 60,
                'motivation'     => 70,
                'condition'      => 90,
                'squadRole'      => 'first_team',
            ];
        }

        return [
            'club' => [
                'id'            => $clubId,
                'name'          => $clubName,
                'reputation'    => 42,
                'tier'          => 'regional',
                'stadiumName'   => 'Test Arena',
                'homePrimary'   => '#E53935',
                'homeSecondary' => '#FFFFFF',
                'awayPrimary'   => '#000000',
                'awaySecondary' => '#CCCCCC',
                'badgeShape'    => 'shield',
            ],
            'players' => $players,
            'staff'   => [
                ['id' => 'coach-1', 'role' => 'COACH', 'name' => 'Coach Name', 'nationality' => 'Testland', 'ability' => 70, 'specialisms' => ['pace' => 80]],
                ['id' => 'scout-1', 'role' => 'SCOUT', 'name' => 'Scout Name', 'nationality' => 'Testland', 'ability' => 65, 'judgements' => ['potential' => 75]],
            ],
            'facilities' => [],
        ];
    }

    private function login(Club $club): void
    {
        $this->currentUserId = $club->getUser()->getId();
    }

    /** See AdminMessageControllerTest::authenticatedRequest() for why the kernel reboots per request. */
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

    public function testAvailableIsPublicAndListsOpenInstance(): void
    {
        $template = $this->createTemplate();
        $this->createOpenInstance($template);

        $this->client->request('GET', '/api/competitions/available');
        $this->assertResponseStatusCodeSame(200);

        $body = $this->responseJson();
        $this->assertCount(1, $body['open']);
        $this->assertSame($template->getName(), $body['open'][0]['templateName']);
        $this->assertArrayNotHasKey('eligibility', $body['open'][0], 'No club context on an anonymous request.');
    }

    public function testShowIsPublicAndReturns404ForUnknownInstance(): void
    {
        $this->client->request('GET', '/api/competitions/00000000-0000-0000-0000-000000000000');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRegisterRequiresAuth(): void
    {
        $template = $this->createTemplate();
        $instance = $this->createOpenInstance($template);

        $this->client->request(
            'POST',
            "/api/competitions/{$instance->getId()}/register",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );
        $this->assertResponseStatusCodeSame(401);
    }

    public function testRegisterRejectsIneligibleClub(): void
    {
        $template = $this->createTemplate(minReputation: 90);
        $instance = $this->createOpenInstance($template);
        $club     = $this->createClub('Low Rep FC', reputation: 0);

        $this->login($club);
        $this->authenticatedRequest(
            'POST',
            "/api/competitions/{$instance->getId()}/register",
            json_encode($this->validSnapshotPayload((string) $club->getId(), 'Low Rep FC')),
        );

        $this->assertResponseStatusCodeSame(403);
        $body = $this->responseJson();
        $this->assertSame('not_eligible', $body['error']);
        $this->assertContains('min_reputation', $body['reasons']);
    }

    public function testRegisterRejectsInvalidSnapshot(): void
    {
        $template = $this->createTemplate();
        $instance = $this->createOpenInstance($template);
        $club     = $this->createClub('Sparse FC');

        $this->login($club);
        $this->authenticatedRequest(
            'POST',
            "/api/competitions/{$instance->getId()}/register",
            json_encode(['club' => ['id' => (string) $club->getId()], 'players' => [['id' => 'p1']]]),
        );

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('invalid_snapshot', $this->responseJson()['error']);
    }

    public function testRegisterUnknownInstanceReturns404(): void
    {
        $club = $this->createClub('Nowhere FC');
        $this->login($club);
        $this->authenticatedRequest(
            'POST',
            '/api/competitions/00000000-0000-0000-0000-000000000000/register',
            json_encode($this->validSnapshotPayload((string) $club->getId(), 'Nowhere FC')),
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDuplicateRegistrationIsIdempotent(): void
    {
        $template = $this->createTemplate(capacity: 8);
        $instance = $this->createOpenInstance($template);
        $club     = $this->createClub('Repeat FC');

        $this->login($club);
        $payload = json_encode($this->validSnapshotPayload((string) $club->getId(), 'Repeat FC'));

        $this->authenticatedRequest('POST', "/api/competitions/{$instance->getId()}/register", $payload);
        $this->assertResponseStatusCodeSame(201);
        $firstEntrantId = $this->responseJson()['entrantId'];

        $this->login($club);
        $this->authenticatedRequest('POST', "/api/competitions/{$instance->getId()}/register", $payload);
        $this->assertResponseStatusCodeSame(200, 'A repeat registration replays idempotently, not an error.');
        $this->assertSame($firstEntrantId, $this->responseJson()['entrantId']);
    }

    public function testCapacityFillLocksInstanceGeneratesRoundsAndAllowsResubmit(): void
    {
        $template = $this->createTemplate(capacity: 4);
        $instance = $this->createOpenInstance($template);

        $clubs = [
            $this->createClub('Alpha FC'),
            $this->createClub('Bravo FC'),
            $this->createClub('Charlie FC'),
            $this->createClub('Delta FC'),
        ];

        foreach ($clubs as $i => $club) {
            $this->login($club);
            $this->authenticatedRequest(
                'POST',
                "/api/competitions/{$instance->getId()}/register",
                json_encode($this->validSnapshotPayload((string) $club->getId(), $club->getName())),
            );
            $this->assertResponseStatusCodeSame(201, "Registration {$i} should succeed");
        }

        // Re-fetch the instance through a fresh EM (kernel rebooted on every request above).
        $instance = $this->em->getRepository(ActiveCompetition::class)->find($instance->getId());
        $this->assertSame(ActiveCompetitionStatus::SCHEDULED, $instance->getStatus(), 'Capacity fill should lock the instance synchronously.');
        $this->assertNotNull($instance->getStartsAt());
        $this->assertNotNull($instance->getEndsAt());

        $this->client->request('GET', "/api/competitions/{$instance->getId()}");
        $this->assertResponseStatusCodeSame(200);
        $body = $this->responseJson();
        $this->assertSame('scheduled', $body['status']);
        // 4-entrant capacity -> [SF, FINAL].
        $this->assertCount(2, $body['rounds']);
        $this->assertSame('SF', $body['rounds'][0]['label']);
        $this->assertSame('FINAL', $body['rounds'][1]['label']);
        $this->assertCount(2, $body['rounds'][0]['fixtures'], 'Round 1 pairs all 4 entrants into 2 fixtures.');

        // A resubmission is allowed immediately after lock — round 1 exists and hasn't started.
        $club = $clubs[0];
        $this->login($club);
        $this->authenticatedRequest(
            'POST',
            "/api/competitions/{$instance->getId()}/resubmit",
            json_encode(array_merge(
                $this->validSnapshotPayload((string) $club->getId(), $club->getName()),
                ['clubId' => (string) $club->getId()],
            )),
        );
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('accepted', $this->responseJson()['status']);
        $this->assertSame(2, $this->responseJson()['snapshotVersion']);
    }

    public function testResubmitUnknownClubReturns404NotRegistered(): void
    {
        $template = $this->createTemplate();
        $instance = $this->createOpenInstance($template);
        $outsider = $this->createClub('Outsider FC');

        $this->login($outsider);
        $this->authenticatedRequest(
            'POST',
            "/api/competitions/{$instance->getId()}/resubmit",
            json_encode(array_merge(
                $this->validSnapshotPayload((string) $outsider->getId(), 'Outsider FC'),
                ['clubId' => (string) $outsider->getId()],
            )),
        );

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('not_registered', $this->responseJson()['error']);
    }

    public function testRegisterPersistsNewSnapshotFieldsVerbatim(): void
    {
        $template = $this->createTemplate();
        $instance = $this->createOpenInstance($template);
        $club     = $this->createClub('Rich Snapshot FC');

        $this->login($club);
        $this->authenticatedRequest(
            'POST',
            "/api/competitions/{$instance->getId()}/register",
            json_encode($this->snapshotPayloadWithNewFields((string) $club->getId(), 'Rich Snapshot FC')),
        );
        $this->assertResponseStatusCodeSame(201);

        $entrant = $this->em->getRepository(CompetitionEntrant::class)->findOneBy([
            'activeCompetition' => $instance,
            'club'              => $club,
        ]);
        $snapshot = $entrant->getSnapshotJson();

        $this->assertSame(42, $snapshot['club']['reputation']);
        $this->assertSame('regional', $snapshot['club']['tier']);
        $this->assertSame('shield', $snapshot['club']['badgeShape']);
        $this->assertSame(80, $snapshot['players'][0]['potential']);
        $this->assertSame('first_team', $snapshot['players'][0]['squadRole']);
        $this->assertSame(15, $snapshot['players'][0]['personality']['determination']);
        $this->assertSame(['pace' => 80], $snapshot['staff'][0]['specialisms']);
        $this->assertSame(['potential' => 75], $snapshot['staff'][1]['judgements']);
    }

    public function testPublicBracketViewNeverExposesPotentialToRivalClubs(): void
    {
        // potential is explicitly flagged sensitive by the client team (normally hidden
        // from opponents, only discoverable via in-game scouting) — this pins the current
        // safe behavior: the public GET /{id} view only ever returns a hand-picked summary
        // per entrant, never the raw snapshot.
        $template = $this->createTemplate();
        $instance = $this->createOpenInstance($template);
        $club     = $this->createClub('Guarded FC');

        $this->login($club);
        $this->authenticatedRequest(
            'POST',
            "/api/competitions/{$instance->getId()}/register",
            json_encode($this->snapshotPayloadWithNewFields((string) $club->getId(), 'Guarded FC')),
        );
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', "/api/competitions/{$instance->getId()}");
        $this->assertResponseStatusCodeSame(200);
        $this->assertStringNotContainsString('potential', $this->client->getResponse()->getContent());
    }

    /**
     * Regression test: show() used to hardcode 'result' => null for every fixture
     * regardless of whether CompetitionRoundProcessorService had already produced a
     * CompetitionResult — round 1 would complete and advance a winner into round 2, but
     * the client-facing bracket view still showed "result not yet available" for it
     * forever. Fixed by batch-fetching CompetitionResult rows in show().
     */
    public function testShowIncludesResultsOnceRoundsAreProcessed(): void
    {
        $template = $this->createTemplate(capacity: 4);
        $instance = $this->createOpenInstance($template);

        $clubs = [
            $this->createClub('Alpha FC'),
            $this->createClub('Bravo FC'),
            $this->createClub('Charlie FC'),
            $this->createClub('Delta FC'),
        ];

        foreach ($clubs as $club) {
            $this->login($club);
            $this->authenticatedRequest(
                'POST',
                "/api/competitions/{$instance->getId()}/register",
                json_encode($this->validSnapshotPayload((string) $club->getId(), $club->getName())),
            );
            $this->assertResponseStatusCodeSame(201);
        }

        $instance = $this->em->getRepository(ActiveCompetition::class)->find($instance->getId());

        // Backdate round 1 so it's due, then run the same processor the cron command runs.
        $round1 = $this->em->getRepository(\App\Entity\Competition\CompetitionRound::class)
            ->findByCompetitionOrderedByIndex($instance)[0];
        $round1->setScheduledAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $processor = self::getContainer()->get(\App\Service\Competition\CompetitionRoundProcessorService::class);
        $processed = $processor->processDueRounds(new \DateTimeImmutable());
        $this->assertGreaterThan(0, $processed, 'Round 1 should be due and processed.');

        $this->client->request('GET', "/api/competitions/{$instance->getId()}");
        $this->assertResponseStatusCodeSame(200);
        $body = $this->responseJson();

        $round1Fixtures = $body['rounds'][0]['fixtures'];
        $this->assertNotEmpty($round1Fixtures);
        foreach ($round1Fixtures as $fixture) {
            $this->assertSame('complete', strtolower($fixture['status']));
            $this->assertNotNull($fixture['result'], 'A completed fixture must expose its result, not null.');
            $this->assertIsInt($fixture['result']['homeScore']);
            $this->assertIsInt($fixture['result']['awayScore']);

            // Club data must mirror exactly what was stored, so the client can render
            // kits/badges/names without a second lookup.
            $this->assertSame($fixture['home']['clubName'], $fixture['result']['homeClub']['name']);
            $this->assertSame($fixture['away']['clubName'], $fixture['result']['awayClub']['name']);

            $this->assertArrayHasKey('narrativePayload', $fixture['result']);
            $this->assertNotEmpty($fixture['result']['narrativePayload'], 'A freshly generated result must carry a non-empty narrative timeline.');
        }
    }
}

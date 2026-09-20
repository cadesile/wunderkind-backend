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
use App\Message\SendPushNotificationMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;

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

        $this->assertSame(CompetitionDuration::TEN_HOURS->value, $body['open'][0]['durationOption']);
        $this->assertSame([
            'minClubReputation' => 0,
            'minClubAgeSeasons' => 0,
            'allowedTiers'      => null,
        ], $body['open'][0]['entryConditions']);
        $this->assertNull($body['open'][0]['nextRoundLabel'], 'A REGISTERING instance has no rounds yet.');
        $this->assertNull($body['open'][0]['nextRoundAt']);
    }

    public function testActiveExcludesOpenIncludesLockedAndExcludesCompleted(): void
    {
        // Still REGISTERING (not full) — must never appear in /active.
        $openTemplate = $this->createTemplate(capacity: 4);
        $this->createOpenInstance($openTemplate);

        $template = $this->createTemplate(capacity: 4);
        $instance = $this->createOpenInstance($template);
        $clubs    = [
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

        $this->client->request('GET', '/api/competitions/active');
        $this->assertResponseStatusCodeSame(200);
        $body = $this->responseJson();

        $this->assertCount(1, $body['active'], 'Only the filled (SCHEDULED) instance should appear — the still-open one is not full.');
        $activeEntry = $body['active'][0];
        $this->assertSame((string) $instance->getId(), $activeEntry['instanceId']);
        $this->assertSame('scheduled', $activeEntry['status']);
        $this->assertSame(4, $activeEntry['entrantCapacity']);
        $this->assertSame(4, $activeEntry['registeredCount']);
        $this->assertSame(CompetitionDuration::TEN_HOURS->value, $activeEntry['durationOption']);
        $this->assertArrayHasKey('entryConditions', $activeEntry);
        $this->assertArrayHasKey('trophyImage', $activeEntry);
        $this->assertArrayHasKey('trophyColour', $activeEntry);

        // Process both rounds to completion (4-capacity -> [SF, FINAL]) and confirm the
        // instance drops out of /active once it's COMPLETED, despite still being "full".
        // The GET above reboots the kernel (KernelBrowser's default per-request behavior), so
        // $this->em must be refreshed before resuming direct entity manipulation — same reason
        // authenticatedRequest() re-fetches it after every request.
        $this->em  = self::getContainer()->get(EntityManagerInterface::class);
        $processor = self::getContainer()->get(\App\Service\Competition\CompetitionRoundProcessorService::class);
        for ($i = 0; $i < 2; $i++) {
            $instance = $this->em->getRepository(ActiveCompetition::class)->find($instance->getId());
            $round    = $this->em->getRepository(\App\Entity\Competition\CompetitionRound::class)
                ->findByCompetitionOrderedByIndex($instance)[$i];
            $round->setScheduledAt(new \DateTimeImmutable('-1 minute'));
            $this->em->flush();
            $processed = $processor->processDueRounds(new \DateTimeImmutable());
            $this->assertGreaterThan(0, $processed);
        }

        $instance = $this->em->getRepository(ActiveCompetition::class)->find($instance->getId());
        $this->assertSame(ActiveCompetitionStatus::COMPLETED, $instance->getStatus());

        $this->client->request('GET', '/api/competitions/active');
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame([], $this->responseJson()['active'], 'A COMPLETED instance must not appear in /active.');
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

    /**
     * Each authenticatedRequest() reboots the kernel (see its own docblock), so the in-memory
     * transport must be read immediately after the request whose dispatch it's proving —
     * a later request's reboot gives back a fresh, empty transport instance.
     *
     * @return list<SendPushNotificationMessage>
     */
    private function sentPushMessages(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');

        return array_map(
            static fn (Envelope $envelope) => $envelope->getMessage(),
            $transport->getSent(),
        );
    }

    public function testNewRegistrantNotifiesExistingEntrantsButNotTheFirstOne(): void
    {
        $template = $this->createTemplate(capacity: 4);
        $instance = $this->createOpenInstance($template);

        $alpha = $this->createClub('Alpha FC');
        $this->login($alpha);
        $this->authenticatedRequest(
            'POST',
            "/api/competitions/{$instance->getId()}/register",
            json_encode($this->validSnapshotPayload((string) $alpha->getId(), $alpha->getName())),
        );
        $this->assertResponseStatusCodeSame(201);
        $this->assertSame([], $this->sentPushMessages(), 'The first registrant has no one to notify.');

        $bravo = $this->createClub('Bravo FC');
        $this->login($bravo);
        $this->authenticatedRequest(
            'POST',
            "/api/competitions/{$instance->getId()}/register",
            json_encode($this->validSnapshotPayload((string) $bravo->getId(), $bravo->getName())),
        );
        $this->assertResponseStatusCodeSame(201);

        $messages = $this->sentPushMessages();
        $this->assertCount(1, $messages);
        $this->assertSame([(string) $alpha->getUser()->getId()], $messages[0]->userIds);
        $this->assertSame('NEW_REGISTRANT', $messages[0]->data['type']);
        $this->assertSame((string) $instance->getId(), $messages[0]->data['competitionId']);
    }

    public function testCapacityFillAlsoNotifiesEveryEntrantThatTheirRoundIsDrawn(): void
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

        // The 4th (capacity-filling) registration's request fires both the NEW_REGISTRANT
        // push (to the other 3) and the ROUND_DRAWN push (to all 4, via CompetitionLockService).
        $messages   = $this->sentPushMessages();
        $roundDrawn = array_values(array_filter($messages, static fn ($m) => $m->data['type'] === 'ROUND_DRAWN'));

        $this->assertCount(1, $roundDrawn);
        $this->assertCount(4, $roundDrawn[0]->userIds, 'All 4 entrants placed into round 1 must be notified.');

        $expectedUserIds = array_map(static fn (Club $c) => (string) $c->getUser()->getId(), $clubs);
        sort($expectedUserIds);
        $actualUserIds = $roundDrawn[0]->userIds;
        sort($actualUserIds);
        $this->assertSame($expectedUserIds, $actualUserIds);
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

        // Round 1 (SF) just completed and flipped the instance to RUNNING; FINAL is next up.
        $this->assertSame('running', $body['status']);
        $this->assertSame(4, $body['entrantCapacity']);
        $this->assertSame(4, $body['registeredCount']);
        $this->assertSame(CompetitionDuration::TEN_HOURS->value, $body['durationOption']);
        $this->assertSame([
            'minClubReputation' => 0,
            'minClubAgeSeasons' => 0,
            'allowedTiers'      => null,
        ], $body['entryConditions']);
        $this->assertSame('FINAL', $body['nextRoundLabel']);
        $this->assertSame($body['rounds'][1]['scheduledAt'], $body['nextRoundAt']);

        // The same instance, now RUNNING, must carry the same new fields via /available.
        $this->client->request('GET', '/api/competitions/available');
        $this->assertResponseStatusCodeSame(200);
        $availableBody = $this->responseJson();
        $runningEntry  = current(array_filter($availableBody['running'], fn ($r) => $r['instanceId'] === (string) $instance->getId()));
        $this->assertNotFalse($runningEntry, 'The RUNNING instance must appear in /available\'s running list.');
        $this->assertSame(4, $runningEntry['entrantCapacity']);
        $this->assertSame(4, $runningEntry['registeredCount']);
        $this->assertSame(CompetitionDuration::TEN_HOURS->value, $runningEntry['durationOption']);
        $this->assertSame('FINAL', $runningEntry['nextRoundLabel']);
        $this->assertSame($body['nextRoundAt'], $runningEntry['nextRoundAt']);

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

            // No club id in the client-facing payload — a tournament opponent may not exist
            // in any local store, so an id would be dead weight at best (see
            // docs/api/tournament-match-result-payload.md).
            $this->assertArrayNotHasKey('id', $fixture['result']['homeClub']);
            $this->assertArrayNotHasKey('id', $fixture['result']['awayClub']);

            $this->assertArrayHasKey('narrativePayload', $fixture['result']);
            $this->assertNotEmpty($fixture['result']['narrativePayload'], 'A freshly generated result must carry a non-empty narrative timeline.');

            foreach ($fixture['result']['narrativePayload'] as $item) {
                $this->assertArrayHasKey('side', $item, 'Narrative events identify a side (HOME/AWAY/null), never a club id.');
                $this->assertArrayNotHasKey('teamId', $item);
                if ($item['side'] !== null) {
                    $this->assertContains($item['side'], ['HOME', 'AWAY']);
                }
            }

            // The starting XI, with this match's goals/assists/cards/rating, no player id.
            $this->assertCount(11, $fixture['result']['homeLineup']);
            $this->assertCount(11, $fixture['result']['awayLineup']);
            foreach (array_merge($fixture['result']['homeLineup'], $fixture['result']['awayLineup']) as $player) {
                $this->assertArrayNotHasKey('id', $player);
                $this->assertArrayHasKey('name', $player);
                $this->assertArrayHasKey('position', $player);
                $this->assertArrayHasKey('goals', $player);
                $this->assertArrayHasKey('assists', $player);
                $this->assertArrayHasKey('yellowCards', $player);
                $this->assertArrayHasKey('redCards', $player);
                // A whole-number rating (e.g. 6.0) round-trips through JSON as an int, not a
                // float, so assert numeric rather than assertIsFloat to avoid a flaky test.
                $this->assertIsNumeric($player['rating']);
                $this->assertGreaterThanOrEqual(1.0, $player['rating']);
                $this->assertLessThanOrEqual(10.0, $player['rating']);
            }

            // Self-contained: a result handed to MatchUX on its own (not walked down from
            // this same /api/competitions/{id} response) still knows what it's a result of.
            // tournament/round mirror this endpoint's own top-level/round-level fields exactly.
            $this->assertSame((string) $instance->getId(), $fixture['result']['tournament']['instanceId']);
            $this->assertSame($body['templateName'], $fixture['result']['tournament']['templateName']);
            $this->assertSame($body['status'], $fixture['result']['tournament']['status']);
            $this->assertSame($body['startsAt'], $fixture['result']['tournament']['startsAt']);
            $this->assertSame($body['endsAt'], $fixture['result']['tournament']['endsAt']);

            $this->assertSame($body['rounds'][0]['roundIndex'], $fixture['result']['round']['roundIndex']);
            $this->assertSame($body['rounds'][0]['label'], $fixture['result']['round']['label']);
            $this->assertSame($body['rounds'][0]['status'], $fixture['result']['round']['status']);
            $this->assertSame($body['rounds'][0]['scheduledAt'], $fixture['result']['round']['scheduledAt']);

            $this->assertSame($fixture['fixtureId'], $fixture['result']['fixtureId']);
        }
    }
}

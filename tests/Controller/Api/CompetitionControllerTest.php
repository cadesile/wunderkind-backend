<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
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
}

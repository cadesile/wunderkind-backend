<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Club;
use App\Entity\User;
use App\Service\Competition\HallOfFameService;
use App\Tests\Support\EntrantFixtureTrait;
use App\Tests\Support\PlayedCupFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/club/hub — specifically its two Hall of Fame keys.
 *
 * The hub had no test of any kind, which is how it came to carry its own copy of the
 * champions loop: a copy that had already drifted from CompetitionController's (it read the
 * trophy through a serializeInstanceSummary() result rather than normalizeTrophy()) and
 * inherited the same one-row-per-template ceiling. Both now come from HallOfFameService, and
 * what these tests pin is that they stay identical to the dedicated endpoint's — because a
 * silent divergence between two payloads showing "the winners" is exactly what happened last
 * time.
 *
 * Only the champions/history half is covered here. The rest of the aggregate — nextMatch,
 * inbox, challenges, open — is untouched by this change and still untested.
 */
class ClubHubControllerTest extends WebTestCase
{
    use EntrantFixtureTrait;
    use PlayedCupFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private mixed $currentUserId = null;
    private mixed $currentClubId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_result, competition_result_audit, competition_fixture,
                      competition_round, competition_entrant_squad, competition_entrant,
                      active_competition, competition_template CASCADE',
        );
    }

    /**
     * The hub shows the same champions and the same first page of history as
     * GET /api/competitions/champions, and caps the history at its own fixed slice.
     *
     * Seven cups over two templates: more than the hub's limit, so the cap is real, and more
     * than one template, so "one row per template" and "one row per cup" cannot be confused
     * for each other by accident.
     */
    public function testHubMirrorsChampionsAndCarriesAFixedHistorySlice(): void
    {
        $templateA = null;
        $templateB = null;
        for ($i = 1; $i <= 7; $i++) {
            $cup = $this->playedCup(
                $this->em,
                sprintf('Hub Winners %02d FC', $i),
                new \DateTimeImmutable(sprintf('2026-08-%02d 18:00:00', $i)),
                templateId: $i % 2 === 1 ? $templateA : $templateB,
            );
            if ($i % 2 === 1) {
                $templateA ??= $cup->templateId;
            } else {
                $templateB ??= $cup->templateId;
            }
        }

        $this->client->request('GET', '/api/competitions/champions?limit=' . HallOfFameService::HUB_HISTORY_LIMIT);
        $this->assertResponseStatusCodeSame(200);
        $public = $this->responseJson();

        $this->login($this->createClub('Hub Reader FC'));
        $this->authenticatedRequest('GET', '/api/club/hub');
        $this->assertResponseStatusCodeSame(200);
        $hub = $this->responseJson();

        $this->assertCount(2, $hub['champions'], 'two active templates, two current holders');
        $this->assertSame($public['champions'], $hub['champions'], 'the two payloads must not drift');

        $this->assertCount(HallOfFameService::HUB_HISTORY_LIMIT, $hub['history'], 'seven cups, capped at the hub slice');
        $this->assertSame($public['history'], $hub['history'], 'and it is the first page of the same ordering');

        $this->assertArrayNotHasKey('nextCursor', $hub, 'the hub is an aggregate read with no cursor of its own');
    }

    /** With nothing completed, both keys are present and empty rather than missing. */
    public function testHubReportsNoChampionsBeforeAnyCupIsWon(): void
    {
        $this->login($this->createClub('Early Reader FC'));
        $this->authenticatedRequest('GET', '/api/club/hub');
        $this->assertResponseStatusCodeSame(200);
        $hub = $this->responseJson();

        $this->assertSame([], $hub['champions']);
        $this->assertSame([], $hub['history']);
    }

    private function createClub(string $name): Club
    {
        $user = new User('hub-' . uniqid('', true) . '@example.com');
        $user->setPassword('x');
        $user->setRoles([User::ROLE_CLUB]);

        $club = new Club($name, $user);
        $club->setReputation(0);
        $club->setBalance(1_000_000);
        $club->setAbbreviation('HUB');
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

    /** See AdminMessageControllerTest::authenticatedRequest() for why the kernel reboots per request. */
    private function authenticatedRequest(string $method, string $uri): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->em->find(User::class, $this->currentUserId);
        $this->client->loginUser($user, 'api');

        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE'     => 'application/json',
            'HTTP_X_CLUB_ID'   => (string) $this->currentClubId,
        ]);
    }

    /** @return array<string, mixed> */
    private function responseJson(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }
}

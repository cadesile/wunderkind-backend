<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Player;
use App\Entity\PoolConfig;
use App\Entity\User;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Repository\PoolConfigRepository;
use App\Service\YouthPoolService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/players/youth — the youth pool draw.
 *
 * Seeds its own youth players because the endpoint DRAWS rather than generates: with an
 * empty pool every assertion here would pass vacuously on a zero-length response. The
 * fixtures are built directly rather than through PlayerGenerationService so the tier and
 * nationality mix under test is exact rather than a random roll.
 *
 * Every player this test creates is removed in tearDown. The test DB is shared and never
 * rolled back between methods, and these rows sit in the same pool every other pool-reading
 * test draws from, so leaving them behind would skew unrelated suites.
 */
class PlayerYouthControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var string[] ids of players this test persisted */
    private array $seededIds = [];
    private mixed $userId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User('youth-' . uniqid() . '@example.com');
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
        $em = self::getContainer()->get(EntityManagerInterface::class);

        foreach ($this->seededIds as $id) {
            $player = $em->find(Player::class, $id);
            if ($player !== null) {
                $em->remove($player);
            }
        }
        $user = $em->find(User::class, $this->userId);
        if ($user !== null) {
            $em->remove($user);
        }
        $em->flush();

        $this->seededIds = [];
        parent::tearDown();
    }

    public function testReturnsOnlyPlayersAgedTwelveToFourteen(): void
    {
        // Ages either side of the window must not come back, including the exact boundaries.
        $this->seedYouth(40, 'English', 10);
        $this->seedAged(11, 'English', 5);
        $this->seedAged(15, 'English', 5);
        $this->seedAged(22, 'English', 5);

        $body = $this->get('/api/players/youth?country=EN&limit=60');

        $this->assertNotEmpty($body['players'], 'fixtures should have produced a non-empty draw');

        $today = new \DateTimeImmutable('today');
        foreach ($body['players'] as $player) {
            $age = (int) (new \DateTimeImmutable($player['dateOfBirth']))->diff($today)->y;
            $this->assertGreaterThanOrEqual(YouthPoolService::MIN_AGE, $age, "{$player['dateOfBirth']} is too young");
            $this->assertLessThanOrEqual(YouthPoolService::MAX_AGE, $age, "{$player['dateOfBirth']} is too old");
        }
    }

    /** The documented default is 200, applied when the client sends no limit at all. */
    public function testLimitDefaultsToTwoHundred(): void
    {
        $this->seedYouth(210, 'English', 40);

        $body = $this->get('/api/players/youth?country=EN');

        $this->assertSame(YouthPoolService::DEFAULT_LIMIT, $body['distribution']['requested']);
        $this->assertCount(YouthPoolService::DEFAULT_LIMIT, $body['players']);
    }

    public function testRespectsAnExplicitLimit(): void
    {
        $this->seedYouth(60, 'English', 10);

        $body = $this->get('/api/players/youth?country=EN&limit=25');

        $this->assertCount(25, $body['players']);
        $this->assertSame(25, $body['amount']);
    }

    /**
     * The majority should be English, but deliberately NOT all of them — an all-home draw
     * would mean the foreign strata never fired.
     */
    public function testCountryWeightingIsMajorityButNotTotal(): void
    {
        // 110 English + 40 Brazilian: enough English to dominate, enough foreign for the
        // non-matching strata to actually fill. Seeding 150/150 would make an all-English
        // draw the only possible outcome and the assertion below vacuous.
        $this->seedYouth(150, 'English', 110);

        $body = $this->get('/api/players/youth?country=EN&limit=100');

        $total   = count($body['players']);
        $english = 0;
        foreach ($body['players'] as $player) {
            if ($player['nationality'] === 'English') {
                $english++;
            }
        }

        $this->assertGreaterThan($total * 0.6, $english, 'most of the draw should be English');
        $this->assertLessThan($total, $english, 'the draw should not be exclusively English');
        $this->assertSame($english, $body['distribution']['nationalityMatched']);
    }

    /** All four bands must be reachable, with local the bulk and elite the rarest. */
    public function testPoolSpansAllFourTiersWithLocalCommonest(): void
    {
        $this->seedYouth(200, 'English', 120);

        $body = $this->get('/api/players/youth?country=EN&limit=120');

        $byTier = $body['distribution']['byPotentialTier'];
        foreach (['local', 'regional', 'national', 'elite'] as $tier) {
            $this->assertArrayHasKey($tier, $byTier);
            $this->assertGreaterThan(0, $byTier[$tier], "no $tier players in the draw");
        }

        $this->assertGreaterThan($byTier['elite'], $byTier['local'], 'local should outnumber elite');
        $this->assertSame(array_sum($byTier), count($body['players']), 'tier counts must sum to the draw');
    }

    /** Every player carries the field the device classifies on, and the youth marker. */
    public function testResponseShapeMatchesTheCanonicalPlayerSnapshot(): void
    {
        $this->seedYouth(30, 'English', 10);

        $body = $this->get('/api/players/youth?country=EN&limit=10');
        $player = $body['players'][0];

        foreach ([
            'id', 'firstName', 'lastName', 'position', 'nationality', 'countryCode', 'dateOfBirth',
            'contractValue', 'potential', 'currentAbility', 'overall', 'tier', 'potentialTier',
            'morale', 'recruitmentSource', 'isActive', 'physical', 'pace', 'technical', 'vision',
            'power', 'stamina', 'heart', 'personality', 'appearance', 'agent', 'guardians', 'isYouth',
        ] as $key) {
            $this->assertArrayHasKey($key, $player, "youth player is missing '$key'");
        }

        $this->assertTrue($player['isYouth']);
        $this->assertContains($player['potentialTier'], ['local', 'regional', 'national', 'elite']);

        // Pool players have no club FK at all, so no club key may appear in the payload.
        $this->assertArrayNotHasKey('club', $player);
        $this->assertArrayNotHasKey('clubId', $player);
    }

    /**
     * The state the pool is in before anyone seeds youth players: the endpoint must answer
     * 200 with an empty draw and a legible distribution, not an error and not a 500. An
     * under-seeded pool is an empty shelf — the operator finds out from `poolSize`.
     *
     * Asserts internal consistency rather than literal emptiness: the test DB is shared, so
     * another suite's fixtures could legitimately leave youth-aged rows behind.
     */
    public function testEmptyYouthPoolReturnsAWellFormedEmptyDraw(): void
    {
        $body = $this->get('/api/players/youth?country=EN&limit=50');

        $this->assertIsArray($body['players']);
        $this->assertSame(count($body['players']), $body['amount']);
        $this->assertSame(count($body['players']), $body['distribution']['returned']);
        $this->assertSame(50, $body['distribution']['requested']);
        $this->assertGreaterThanOrEqual(0, $body['distribution']['poolSize']);
        $this->assertLessThanOrEqual($body['distribution']['poolSize'], count($body['players']));
        $this->assertSame(
            ['local', 'regional', 'national', 'elite'],
            array_keys($body['distribution']['byPotentialTier']),
        );
    }

    /**
     * The per-tier home-nationality mix lives on PoolConfig so it can be retuned from the
     * admin. This pins that the config is actually read: forcing every tier to 100% home
     * must eliminate foreign players from a draw that would otherwise be ~12.5% foreign
     * with the defaults.
     *
     * Restores the original values afterwards — pool_config is a shared singleton row in a
     * test DB that is never rolled back between methods, so leaving it edited would skew
     * every later test that generates or draws players.
     */
    public function testHomeNationalityMixIsDrivenByPoolConfig(): void
    {
        $this->seedYouth(200, 'English', 100);

        $config = self::getContainer()->get(PoolConfigRepository::class)->getConfig();
        $original = [
            $config->getYouthHomeNationalityPercentLocal(),
            $config->getYouthHomeNationalityPercentRegional(),
            $config->getYouthHomeNationalityPercentNational(),
            $config->getYouthHomeNationalityPercentElite(),
        ];

        try {
            $config->setYouthHomeNationalityPercentLocal(100)
                   ->setYouthHomeNationalityPercentRegional(100)
                   ->setYouthHomeNationalityPercentNational(100)
                   ->setYouthHomeNationalityPercentElite(100);
            $this->em->flush();

            $body = $this->get('/api/players/youth?country=EN&limit=80');

            $this->assertSame(
                count($body['players']),
                $body['distribution']['nationalityMatched'],
                'with every tier at 100% home, the draw should contain no foreign players',
            );
        } finally {
            self::ensureKernelShutdown();
            static::createClient();
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $cfg = self::getContainer()->get(PoolConfigRepository::class)->getConfig();
            $cfg->setYouthHomeNationalityPercentLocal($original[0])
                ->setYouthHomeNationalityPercentRegional($original[1])
                ->setYouthHomeNationalityPercentNational($original[2])
                ->setYouthHomeNationalityPercentElite($original[3]);
            $em->flush();
        }
    }

    public function testRejectsMissingAndUnknownCountry(): void
    {
        $this->request('/api/players/youth');
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->request('/api/players/youth?country=ZZ');
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testRequiresAuthentication(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->request('GET', '/api/players/youth?country=EN');

        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Seeds youth players spread across all four potential bands, $homeCount of them English
     * and the rest foreign, so tier and nationality assertions have something to find.
     */
    private function seedYouth(int $count, string $homeNationality, int $homeCount): void
    {
        // One potential per tier band, picked mid-band so Tier::fromScore is unambiguous.
        $potentials = [8, 25, 55, 85];

        for ($i = 0; $i < $count; $i++) {
            $this->persistPlayer(
                nationality: $i < $homeCount ? $homeNationality : 'Brazilian',
                age:         12 + ($i % 3),
                potential:   $potentials[$i % 4],
            );
        }
        $this->em->flush();
    }

    private function seedAged(int $age, string $nationality, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->persistPlayer($nationality, $age, 40);
        }
        $this->em->flush();
    }

    private function persistPlayer(string $nationality, int $age, int $potential): void
    {
        // Mid-window birthday so a boundary age is unambiguously inside/outside the range
        // regardless of which day the suite runs.
        $dob = (new \DateTimeImmutable('today'))->modify("-{$age} years")->modify('-120 days');

        $player = new Player(
            'Youth', 'Fixture' . uniqid(),
            $dob,
            $nationality,
            PlayerPosition::MIDFIELDER,
            RecruitmentSource::YOUTH_INTAKE,
            $potential,
            (int) round($potential * 0.4),
        );

        $this->em->persist($player);
        $this->seededIds[] = $player->getId();
    }

    private function get(string $uri): array
    {
        $this->request($uri);

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent() ?: '');

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * One authenticated request. loginUser() authenticates exactly ONE request against the
     * stateless JWT firewall, so each call reboots the kernel and logs in again — see
     * AdminMessageControllerTest::authenticatedRequest() for the full reasoning.
     */
    private function request(string $uri): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->client->loginUser($this->em->find(User::class, $this->userId), 'api');
        $this->client->request('GET', $uri);
    }
}

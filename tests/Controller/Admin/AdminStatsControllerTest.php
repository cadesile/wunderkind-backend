<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Enum\LeaderboardCategory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminStatsControllerTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'admin-stats-test-admin@example.com';

    /**
     * app_admin_provider is a Doctrine EntityUserProvider and re-fetches by
     * email on every request, so an in-memory-only Admin reads as
     * unauthenticated. Persist it first. (Same reasoning as SocialAuthControllerTest.)
     */
    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    private function json(KernelBrowser $client): array
    {
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($payload, 'endpoint did not return a JSON object');

        return $payload;
    }

    public function testGrowthReturnsEveryWindowAndAFullTrendSeries(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/stats/growth');
        self::assertResponseIsSuccessful();

        $data = $this->json($client);

        self::assertSame(['24h', '7d', '30d'], $data['windows']);
        self::assertSame(
            ['users', 'clubs', 'syncs', 'invalidSyncs', 'activeClubs'],
            array_keys($data['metrics'])
        );

        foreach ($data['metrics'] as $metric => $counts) {
            foreach (['all', '24h', '7d', '30d'] as $window) {
                self::assertArrayHasKey($window, $counts, "{$metric} is missing the {$window} window");
                self::assertIsInt($counts[$window]);
            }
        }

        // Guest/registered must partition the user total, not overlap it.
        $users = $data['metrics']['users'];
        self::assertSame($users['all'], $users['guest'] + $users['registered']);

        // The series is gap-filled, so it always has one point per day.
        self::assertCount(30, $data['trend']['days']);
        foreach (['users', 'clubs', 'syncs'] as $series) {
            self::assertCount(30, $data['trend']['series'][$series], "{$series} series is not gap-filled");
        }
    }

    public function testLeaderboardsCoverEveryCategoryCappedAtTen(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/stats/leaderboards');
        self::assertResponseIsSuccessful();

        $data = $this->json($client);

        self::assertSame('all-time', $data['period']);
        self::assertCount(count(LeaderboardCategory::cases()), $data['boards']);

        $returned = array_column($data['boards'], 'category');
        foreach (LeaderboardCategory::cases() as $category) {
            self::assertContains($category->value, $returned);
        }

        foreach ($data['boards'] as $board) {
            self::assertLessThanOrEqual(10, count($board['entries']));
            foreach ($board['entries'] as $entry) {
                self::assertSame(
                    ['rank', 'clubId', 'clubName', 'score', 'displayLabel'],
                    array_keys($entry)
                );
            }
        }
    }

    /** @return list<array{string}> */
    public static function poolEntityProvider(): array
    {
        return [['players'], ['staff'], ['scouts'], ['agents'], ['world']];
    }

    #[DataProvider('poolEntityProvider')]
    public function testPoolBreakdownSharesOneShapeAcrossEntities(string $entity): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', "/admin/stats/pool/{$entity}");
        self::assertResponseIsSuccessful();

        $data = $this->json($client);

        self::assertIsInt($data['total']);
        self::assertNotEmpty($data['facets']);

        foreach ($data['facets'] as $name => $facet) {
            foreach ($facet as $row) {
                self::assertSame(['key', 'count'], array_keys($row), "facet {$name} has the wrong row shape");
                self::assertIsInt($row['count']);
            }
        }

        // The nested block drives the drill-down table; the renderer iterates
        // `children` blindly, so every row must carry every declared dimension.
        self::assertArrayHasKey('dimension', $data['nested']);
        self::assertNotEmpty($data['nested']['children']);

        foreach ($data['nested']['rows'] as $row) {
            self::assertSame(['key', 'count', 'children'], array_keys($row));
            foreach ($data['nested']['children'] as $dimension) {
                self::assertArrayHasKey($dimension, $row['children']);
            }
        }
    }

    public function testUnknownPoolEntityIsNotRoutable(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/stats/pool/wizards');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRefreshRejectsAMissingCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->xmlHttpRequest('POST', '/admin/stats/refresh', ['panel' => 'growth']);
        self::assertResponseStatusCodeSame(400);
    }

    /** @return list<array{string}> */
    public static function endpointProvider(): array
    {
        return [
            ['/admin/stats/growth'],
            ['/admin/stats/leaderboards'],
            ['/admin/stats/pool/players'],
        ];
    }

    #[DataProvider('endpointProvider')]
    public function testEndpointsAreNotPubliclyReadable(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseStatusCodeSame(
            302,
            'the admin firewall should redirect an anonymous request to the login form'
        );
    }
}

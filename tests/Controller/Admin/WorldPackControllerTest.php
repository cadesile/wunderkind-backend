<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Entity\WorldPack\WorldPackGenerationClubRun;
use App\Entity\WorldPack\WorldPackGenerationRun;
use App\Entity\WorldPack\WorldPackGenerationTierRun;
use App\Enum\CitySize;
use App\Enum\WorldPack\WorldPackGenerationClubRunStatus;
use App\Enum\WorldPack\WorldPackGenerationRunStatus;
use App\Enum\WorldPack\WorldPackGenerationTierRunStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WorldPackControllerTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'worldpack-controller-test-admin@example.com';
    private const COUNTRY          = 'PT'; // low collision risk with other suites' fixtures
    private const TIER             = 6;

    private array $cleanup = [];

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $em->remove($managed);
            }
        }
        $em->flush();
        parent::tearDown();
    }

    private function track(object $entity): object
    {
        $this->cleanup[] = $entity;
        return $entity;
    }

    private function loginAsAdmin(KernelBrowser $client): void
    {
        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(Admin::class)->findOneBy(['email' => self::TEST_ADMIN_EMAIL]);
        if ($admin === null) {
            $admin = new Admin(self::TEST_ADMIN_EMAIL);
            $admin->setPassword('not-used-for-login-here');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    /**
     * These endpoints are JSON-only (no rendered form), but the admin/worldpack_cache
     * page embeds both tokens as JS constants for its own fetch() calls — extract them
     * from a real page load rather than resolving CsrfTokenManagerInterface directly
     * from the test container, which has no session outside an active request.
     */
    private function fetchCsrfTokens(KernelBrowser $client): array
    {
        $crawler = $client->request('GET', '/admin', ['routeName' => 'admin_worldpack_cache']);
        $html    = $crawler->html();

        preg_match("/const REGENERATE_TOKEN\s*=\s*'([^']+)';/", $html, $regenMatch);
        preg_match("/const RETRY_TOKEN\s*=\s*'([^']+)';/", $html, $retryMatch);

        return [
            'regenerate' => $regenMatch[1] ?? self::fail('Could not find REGENERATE_TOKEN in page'),
            'retry'      => $retryMatch[1] ?? self::fail('Could not find RETRY_TOKEN in page'),
        ];
    }

    private function seedLeague(EntityManagerInterface $em, string $country, int $tier): League
    {
        $league = $this->track(new League($country, $tier, "WorldPackControllerTest League {$tier}"));
        $em->persist($league);

        $npc = new NpcClub('WPCT FC', $country, $tier, 20, '#111111', '#eeeeee', 1_000_000, [], citySize: CitySize::MEDIUM);
        $npc->setLeague($league);
        $em->persist($npc);
        $this->track($npc);

        $em->flush();

        return $league;
    }

    public function testCachePageLoadsForAnAdmin(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin', ['routeName' => 'admin_worldpack_cache']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h5', 'Worldpack Cache');
    }

    public function testRegenerateCountryStartsARunAndReturnsItsId(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedLeague($em, self::COUNTRY, self::TIER);
        $tokens = $this->fetchCsrfTokens($client);

        $client->request('POST', '/admin/worldpack-cache/regenerate-country', [
            '_token'  => $tokens['regenerate'],
            'country' => self::COUNTRY,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertSame(self::COUNTRY, $data['country']);
        self::assertNotEmpty($data['runId']);

        $run = self::getContainer()->get(EntityManagerInterface::class)->find(WorldPackGenerationRun::class, $data['runId']);
        self::assertNotNull($run);
        $this->track($run);
    }

    public function testRegenerateCountryRejectsAlreadyActiveRun(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedLeague($em, self::COUNTRY, self::TIER);
        $tokens = $this->fetchCsrfTokens($client);

        $client->request('POST', '/admin/worldpack-cache/regenerate-country', [
            '_token'  => $tokens['regenerate'],
            'country' => self::COUNTRY,
        ]);
        self::assertResponseIsSuccessful();
        $firstRunId = json_decode((string) $client->getResponse()->getContent(), true)['runId'];
        $run        = self::getContainer()->get(EntityManagerInterface::class)->find(WorldPackGenerationRun::class, $firstRunId);
        $this->track($run);

        // CSRF tokens are session-bound, and the session cookie persists across
        // requests on the same KernelBrowser client, so the token fetched above is
        // still valid for this second request.
        $client->request('POST', '/admin/worldpack-cache/regenerate-country', [
            '_token'  => $tokens['regenerate'],
            'country' => self::COUNTRY,
        ]);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['success']);
        self::assertNotEmpty($data['error']);
    }

    public function testRegenerateCountryRejectsInvalidCsrfToken(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('POST', '/admin/worldpack-cache/regenerate-country', [
            '_token'  => 'not-a-real-token',
            'country' => self::COUNTRY,
        ]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testRunStatusReturnsNullRunWhenNoneStarted(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);

        $client->request('GET', '/admin/worldpack-cache/run-status/ZZ');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('ZZ', $data['country']);
        self::assertNull($data['run']);
    }

    public function testRunStatusReflectsTierAndClubProgress(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $league = $this->seedLeague($em, self::COUNTRY, self::TIER);
        $npc    = $em->getRepository(NpcClub::class)->findOneBy(['league' => $league]);

        $run = $this->track(new WorldPackGenerationRun(self::COUNTRY, [self::TIER]));
        $run->setStatus(WorldPackGenerationRunStatus::IN_PROGRESS);
        $em->persist($run);

        $tierRun = $this->track(new WorldPackGenerationTierRun($run, self::COUNTRY, self::TIER));
        $tierRun->setStatus(WorldPackGenerationTierRunStatus::IN_PROGRESS);
        $tierRun->setTotalClubCount(1);
        $em->persist($tierRun);

        $clubRun = $this->track(new WorldPackGenerationClubRun($tierRun, $npc));
        $clubRun->setStatus(WorldPackGenerationClubRunStatus::GENERATING_PLAYERS);
        $em->persist($clubRun);

        $em->flush();

        $client->request('GET', '/admin/worldpack-cache/run-status/' . self::COUNTRY);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(self::COUNTRY, $data['country']);
        self::assertSame('in_progress', $data['run']['status']);
        self::assertCount(1, $data['run']['tiers']);

        $tier = $data['run']['tiers'][0];
        self::assertSame(self::TIER, $tier['tier']);
        self::assertSame('in_progress', $tier['status']);
        self::assertSame(1, $tier['totalClubs']);
        self::assertCount(1, $tier['clubs']);
        self::assertSame('generating_players', $tier['clubs'][0]['status']);
        self::assertSame($npc->getName(), $tier['clubs'][0]['name']);
    }

    public function testRetryFailedClubsResetsFailedRowsAndReopensTierAndRun(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $league = $this->seedLeague($em, self::COUNTRY, self::TIER);
        $npc    = $em->getRepository(NpcClub::class)->findOneBy(['league' => $league]);

        $run = $this->track(new WorldPackGenerationRun(self::COUNTRY, [self::TIER]));
        $run->setStatus(WorldPackGenerationRunStatus::COMPLETED_WITH_ERRORS);
        $run->setFinishedAt(new \DateTimeImmutable());
        $em->persist($run);

        $tierRun = $this->track(new WorldPackGenerationTierRun($run, self::COUNTRY, self::TIER));
        $tierRun->setStatus(WorldPackGenerationTierRunStatus::FAILED);
        $tierRun->setTotalClubCount(1);
        $tierRun->setFailedClubCount(1);
        $tierRun->setErrorMessage('one or more clubs failed');
        $tierRun->setFinishedAt(new \DateTimeImmutable());
        $em->persist($tierRun);

        $clubRun = $this->track(new WorldPackGenerationClubRun($tierRun, $npc));
        $clubRun->setStatus(WorldPackGenerationClubRunStatus::FAILED);
        $clubRun->setErrorMessage('boom');
        $em->persist($clubRun);

        $em->flush();
        $runId     = $run->getId();
        $tierRunId = $tierRun->getId();
        $clubRunId = $clubRun->getId();

        // Fetching the tokens is itself a request, which reboots the kernel (and its
        // container) — do it after the fixtures above are committed to the real DB, and
        // before re-resolving any entity so nothing below touches a stale EM reference.
        $tokens = $this->fetchCsrfTokens($client);

        $client->request('POST', "/admin/worldpack-cache/retry-failed-clubs/{$tierRunId->toRfc4122()}", [
            '_token' => $tokens['retry'],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertSame(1, $data['retried']);

        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $run     = $em->find(WorldPackGenerationRun::class, $runId);
        $tierRun = $em->find(WorldPackGenerationTierRun::class, $tierRunId);
        $clubRun = $em->find(WorldPackGenerationClubRun::class, $clubRunId);

        self::assertSame(WorldPackGenerationClubRunStatus::PENDING, $clubRun->getStatus());
        self::assertNull($clubRun->getErrorMessage());

        self::assertSame(WorldPackGenerationTierRunStatus::IN_PROGRESS, $tierRun->getStatus());
        self::assertNull($tierRun->getErrorMessage());
        self::assertNull($tierRun->getFinishedAt());

        self::assertSame(WorldPackGenerationRunStatus::IN_PROGRESS, $run->getStatus());
        self::assertNull($run->getFinishedAt());
    }

    public function testRetryFailedClubsReturnsNotFoundForUnknownTierRun(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $tokens = $this->fetchCsrfTokens($client);

        $client->request('POST', '/admin/worldpack-cache/retry-failed-clubs/' . '01931234-0000-7000-8000-000000000000', [
            '_token' => $tokens['retry'],
        ]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Admin;
use App\Entity\WorldPack\WorldPackSet;
use App\Enum\WorldPack\WorldPackSetStatus;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the TOP UP +1 button is allowed to tell an operator.
 *
 * 🔴 The bug these pin is a report, not a computation. `deficit()` counts BUILDING sets
 * towards the buffer — correctly, so a burst of inits does not queue one build each — so a
 * country with ZERO claimable sets and a couple of stranded BUILDING rows is "not short". The
 * endpoint used to answer that with `success: true, queued: false, reason: already at target`,
 * while the stock column one cell to the left of the button said `0/2 SHORT` and the button was
 * enabled on exactly that test. An operator was told the opposite of what the row said, and
 * nothing was written. That no-op silently invalidated about three hours of device testing on
 * 2026-11-20; a refusal would have cost one press.
 */
class WorldPackTopUpTruthTest extends WebTestCase
{
    private const TEST_ADMIN_EMAIL = 'worldpack-topup-truth-admin@example.com';

    private array $cleanup = [];
    private ?string $country = null;

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
        $this->cleanup = [];
        $this->country = null;

        parent::tearDown();
    }

    /** The headline: nothing queued must never be reported as success. */
    public function testAStarvedCountryIsRefusedRatherThanReportedAtTarget(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $token = $this->topUpToken($client);

        // Two builds in flight, nothing claimable — the state a lost worker leaves.
        $this->makeSet(WorldPackSetStatus::BUILDING);
        $this->makeSet(WorldPackSetStatus::BUILDING);

        $client->request('POST', '/admin/worldpack-cache/top-up', [
            '_token'  => $token,
            'country' => $this->country(),
        ]);

        self::assertSame(409, $client->getResponse()->getStatusCode());

        $payload = json_decode($client->getResponse()->getContent(), true);

        self::assertFalse($payload['success'], 'a no-op must not report success');
        self::assertFalse($payload['queued']);
        self::assertSame(0, $payload['claimable']);
        self::assertSame(2, $payload['building']);
        self::assertStringContainsString(
            'NOTHING was queued',
            $payload['error'],
            'the refusal has to say that nothing happened, in words',
        );
        self::assertStringContainsString($this->country(), $payload['error']);
    }

    /** A genuinely short country still queues, and now says what it is short of. */
    public function testAShortCountryQueuesAndReportsTheFigures(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $token = $this->topUpToken($client);

        $client->request('POST', '/admin/worldpack-cache/top-up', [
            '_token'  => $token,
            'country' => $this->country(),
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true);

        self::assertTrue($payload['success']);
        self::assertTrue($payload['queued']);
        self::assertSame(0, $payload['claimable']);
        self::assertArrayHasKey('target', $payload);
    }

    /**
     * An honest "already at target" survives — the point is to tell the two apart, not to make
     * every answer a refusal.
     */
    public function testAStockedCountryStillReportsAtTargetWithItsFigures(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $token = $this->topUpToken($client);

        $target = self::getContainer()
            ->get(\App\Service\WorldPack\WorldPackSetTopUpService::class)
            ->targetSetCount();

        for ($i = 0; $i < $target; $i++) {
            $this->makeSet(WorldPackSetStatus::READY);
        }

        $client->request('POST', '/admin/worldpack-cache/top-up', [
            '_token'  => $token,
            'country' => $this->country(),
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true);

        self::assertTrue($payload['success']);
        self::assertFalse($payload['queued']);
        self::assertSame('already at target', $payload['reason']);
        self::assertSame($target, $payload['claimable']);
    }

    /**
     * A build nothing is finishing is reaped on the way in, so the press that used to be a
     * silent no-op becomes a queued top-up AND leaves a FAILED row with a reason behind it.
     */
    public function testAStrandedBuildIsReapedAndTheTopUpThenQueues(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client);
        $token = $this->topUpToken($client);

        $stranded = $this->makeSet(WorldPackSetStatus::BUILDING);
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            "UPDATE world_pack_set SET generated_at = NOW() - INTERVAL '2 hours' WHERE id = :id",
            ['id' => $stranded->getId()->toRfc4122()],
        );
        $em->clear();

        $client->request('POST', '/admin/worldpack-cache/top-up', [
            '_token'  => $token,
            'country' => $this->country(),
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true);

        self::assertSame(1, $payload['reaped']);
        self::assertTrue($payload['queued'], 'once the dead build is out of the way the country is short again');

        $reloaded = self::getContainer()
            ->get(EntityManagerInterface::class)
            ->find(WorldPackSet::class, (string) $stranded->getId());
        self::assertSame(WorldPackSetStatus::FAILED, $reloaded->getStatus());
        self::assertStringContainsString('Build never completed', (string) $reloaded->getErrorMessage());
    }

    private function makeSet(WorldPackSetStatus $status): WorldPackSet
    {
        $em  = self::getContainer()->get(EntityManagerInterface::class);
        $set = new WorldPackSet(
            $this->country(),
            WorldInitializationService::WORLD_PACK_VERSION,
            ['players' => [['id' => 'p1']], 'staff' => [], 'scouts' => []],
            4,
        );
        $set->setStatus($status);
        if ($status === WorldPackSetStatus::READY) {
            $set->setReadyAt(new \DateTimeImmutable());
        }

        $em->persist($set);
        $em->flush();
        $this->cleanup[] = $set;

        return $set;
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
     * The page embeds the token as a JS variable for its own fetch(); take it from a real page
     * load rather than the CSRF manager, which has no session outside an active request. The
     * declaration keyword is not part of the contract — see WorldPackControllerTest.
     */
    private function topUpToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin', ['routeName' => 'admin_worldpack_cache']);
        preg_match("/(?:const|let|var)\s+TOP_UP_TOKEN\s*=\s*'([^']+)'/", $crawler->html(), $m);

        self::assertNotEmpty($m[1] ?? null, 'the page must expose TOP_UP_TOKEN for its own button');

        return $m[1];
    }

    /** Lazily, because the kernel must not be booted before createClient(). */
    private function country(): string
    {
        return $this->country ??= $this->unusedCountryCode();
    }

    private function unusedCountryCode(): string
    {
        $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        for ($i = 0; $i < 50; $i++) {
            $code = chr(random_int(65, 90)) . chr(random_int(65, 90));
            $used = (int) $conn->fetchOne('SELECT count(*) FROM world_pack_set WHERE country = :c', ['c' => $code]);
            if ($used === 0) {
                return $code;
            }
        }

        self::fail('could not find an unused country code');
    }
}

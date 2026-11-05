<?php

declare(strict_types=1);

namespace App\Tests\Service\Friendly;

use App\Entity\Club;
use App\Entity\Friendly\FriendlyChallenge;
use App\Entity\InboxMessage;
use App\Entity\User;
use App\Enum\Friendly\FriendlyChallengeStatus;
use App\Message\Friendly\ResolveFriendlyMatchMessage;
use App\MessageHandler\Friendly\ResolveFriendlyMatchMessageHandler;
use App\Service\Friendly\FriendlyChallengeService;
use App\Tests\Support\FriendlyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\UuidV7;

/**
 * Covers the two halves of how a friendly actually gets resolved in production: the cron
 * tick queues, and the claim behind the handler is a LEASE rather than a one-way flag.
 *
 * The lease is the part with history. `resolve_locked_at IS NULL` made a claim
 * unreleasable — the claim commits through raw SQL, so any failure between it and the single
 * flush at the end of resolveSingleMatch() left the column set with no predicate that could
 * ever match it again. The challenge stayed ACCEPTED and claimed, every later tick skipped
 * it, and both clubs' stakes stayed escrowed with no way back short of clearing the column
 * by hand. Nothing caught that because nothing resolved friendlies at all until the cron
 * entries landed. testAStaleClaimIsReclaimed() is the regression test.
 *
 * Nothing here asserts an absolute count out of dispatchDueMatches(): this suite shares one
 * database with no per-test rollback, so other tests' due challenges are legitimately in
 * flight. Every assertion is keyed to the challenge the test seeded.
 */
class FriendlyResolveQueueingTest extends KernelTestCase
{
    use FriendlyFixtureTrait;

    private EntityManagerInterface $em;
    private FriendlyChallengeService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(FriendlyChallengeService::class);
    }

    public function testADueMatchIsQueuedRatherThanResolvedInline(): void
    {
        $challenge = $this->seedAcceptedChallenge();

        $queued = $this->service->dispatchDueMatches(new \DateTimeImmutable());

        self::assertGreaterThanOrEqual(1, $queued);
        self::assertContains((string) $challenge->getId(), $this->queuedChallengeIds());

        // The tick must not have done the work itself — that is the whole point of the split.
        $this->em->refresh($challenge);
        self::assertSame(FriendlyChallengeStatus::ACCEPTED, $challenge->getStatus());
        self::assertNull($challenge->getResolvedAt());
        self::assertNull($challenge->getHomeScore());
    }

    public function testQueueingTakesNoClaim(): void
    {
        $challenge = $this->seedAcceptedChallenge();

        $this->service->dispatchDueMatches(new \DateTimeImmutable());

        // Claiming at dispatch time would start the lease counting down while the message sat
        // in the queue, so a backlog would silently expire its own claims.
        $this->em->refresh($challenge);
        self::assertNull($challenge->getResolveLockedAt());
    }

    public function testTheQueuedMessageResolvesTheChallenge(): void
    {
        $challenge = $this->seedAcceptedChallenge();

        $this->handle((string) $challenge->getId());

        $this->em->refresh($challenge);
        self::assertSame(FriendlyChallengeStatus::COMPLETE, $challenge->getStatus());
        self::assertNotNull($challenge->getHomeScore());
        self::assertNotNull($challenge->getAwayScore());
    }

    public function testResolutionReleasesTheClaim(): void
    {
        $challenge = $this->seedAcceptedChallenge();

        self::assertTrue($this->service->resolveIfClaimable($challenge, new \DateTimeImmutable()));

        // Released in the same flush as COMPLETE, so "locked and still ACCEPTED" means
        // exactly "being resolved right now, or a tick died mid-resolve".
        $this->em->refresh($challenge);
        self::assertNull($challenge->getResolveLockedAt());
        self::assertSame(FriendlyChallengeStatus::COMPLETE, $challenge->getStatus());
    }

    public function testAFreshClaimKeepsASecondResolverOut(): void
    {
        $challenge = $this->seedAcceptedChallenge();
        $challenge->setResolveLockedAt(new \DateTimeImmutable('-10 seconds'));
        $this->em->flush();

        self::assertFalse($this->service->resolveIfClaimable($challenge, new \DateTimeImmutable()));

        $this->em->refresh($challenge);
        self::assertSame(FriendlyChallengeStatus::ACCEPTED, $challenge->getStatus());
        self::assertNull($challenge->getResolvedAt());
    }

    /**
     * The regression test for the unreleasable claim.
     *
     * Before the lease, a challenge in this state — claimed, still ACCEPTED, the claiming run
     * long gone — was stranded permanently, with both stakes escrowed behind it.
     */
    public function testAStaleClaimIsReclaimed(): void
    {
        $challenge = $this->seedAcceptedChallenge();
        $challenge->setResolveLockedAt(new \DateTimeImmutable(
            sprintf('-%d seconds', FriendlyChallenge::CLAIM_LEASE_SECONDS + 60),
        ));
        $this->em->flush();

        self::assertTrue($this->service->resolveIfClaimable($challenge, new \DateTimeImmutable()));

        $this->em->refresh($challenge);
        self::assertSame(FriendlyChallengeStatus::COMPLETE, $challenge->getStatus());
        self::assertNotNull($challenge->getHomeScore());
    }

    public function testADuplicateDeliveryPaysNothingTwice(): void
    {
        $challenge   = $this->seedAcceptedChallenge();
        $challengeId = (string) $challenge->getId();

        $this->handle($challengeId);
        $notesAfterFirst = $this->resultNotesFor($challengeId);

        // A second delivery of the same message is expected — dispatch is not deduped.
        $this->handle($challengeId);

        self::assertSame(2, $notesAfterFirst, 'one sealed note per side');
        self::assertSame(
            $notesAfterFirst,
            $this->resultNotesFor($challengeId),
            'a replayed resolve must not write a second set of notes — each carries the stake as a ledger_delta',
        );
    }

    public function testTheHandlerToleratesAChallengeThatNoLongerExists(): void
    {
        // Deleted between dispatch and delivery — ordinary, not an error.
        $this->handle((string) new UuidV7());

        $this->expectNotToPerformAssertions();
    }

    // --- helpers ---------------------------------------------------------------------

    private function handle(string $challengeId): void
    {
        $handler = self::getContainer()->get(ResolveFriendlyMatchMessageHandler::class);
        $handler(new ResolveFriendlyMatchMessage($challengeId));
    }

    /** @return list<string> */
    private function queuedChallengeIds(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.friendly');

        return array_values(array_map(
            static fn (ResolveFriendlyMatchMessage $m) => $m->challengeId,
            array_filter(
                array_map(static fn (Envelope $e) => $e->getMessage(), $transport->getSent()),
                static fn ($m) => $m instanceof ResolveFriendlyMatchMessage,
            ),
        ));
    }

    private function resultNotesFor(string $challengeId): int
    {
        $notes = $this->em->getRepository(InboxMessage::class)->findBy([
            'relatedEntityType' => 'friendly_challenge',
            'relatedEntityId'   => $challengeId,
        ]);

        return count(array_filter(
            $notes,
            static fn (InboxMessage $m) => ($m->getOfferData()['type'] ?? null) === 'FRIENDLY_RESULT',
        ));
    }

    private function club(string $name): Club
    {
        $user = new User(sprintf('friendly-queue-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);

        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    /** @return array<string, mixed> */
    private function snapshot(string $clubName, int $ability): array
    {
        $players   = [];
        $positions = ['GK', 'DEF', 'DEF', 'DEF', 'DEF', 'MID', 'MID', 'MID', 'ATT', 'ATT', 'ATT'];
        foreach ($positions as $i => $position) {
            $players[] = [
                'id'             => sprintf('%s-p%d', $clubName, $i),
                'name'           => sprintf('%s Player %d', $clubName, $i),
                'position'       => $position,
                'currentAbility' => $ability,
            ];
        }

        return [
            'club'    => ['name' => $clubName, 'formation' => '4-4-2', 'cohesion' => 70, 'managerMotivation' => 65],
            'players' => $players,
            'staff'   => [['role' => 'MANAGER', 'name' => $clubName . ' Boss', 'ability' => 70]],
        ];
    }

    private function seedAcceptedChallenge(int $stakePence = 25_000): FriendlyChallenge
    {
        $challenger = $this->club('Challenger ' . uniqid('', true));
        $acceptor   = $this->club('Acceptor ' . uniqid('', true));

        $challenge = $this->makeOpenChallenge(
            $this->em,
            $challenger,
            $this->snapshot('Challenger FC', 70),
            'home',
            $stakePence,
        );
        $this->acceptChallengeFixture($this->em, $challenge, $acceptor, $this->snapshot('Acceptor FC', 40));
        $challenge->setStatus(FriendlyChallengeStatus::ACCEPTED);

        $this->em->flush();

        return $challenge;
    }
}

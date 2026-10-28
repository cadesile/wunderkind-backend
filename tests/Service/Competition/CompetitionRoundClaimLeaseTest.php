<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionRound;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionDrawService;
use App\Service\Competition\CompetitionLockService;
use App\Service\Competition\CompetitionResultsService;
use App\Tests\Support\EntrantFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The draw and resolve claims are a **lease**, and the status transition is atomic with the
 * work it describes.
 *
 * Both properties exist because of concrete ways a round became permanently unprocessable,
 * which matters more the more tournaments run concurrently:
 *
 *  - `claim*()` set `*_locked_at` and nothing ever cleared it, so any exception between
 *    claiming a round and finishing it left a lock no predicate could match again. The round
 *    was skipped by every later tick and only an admin force-resolving each fixture by hand
 *    could recover it.
 *  - `claimDraw()` additionally committed `status = DRAWN` through raw SQL *before*
 *    `drawRound()` created a single fixture. A failure in that flush left a round marked
 *    DRAWN with no fixtures and `matches_resolve_at` NULL — invisible to `findDueForDraw()`
 *    (which wants DRAW_PENDING) *and* to `findDueForResults()` (`matches_resolve_at <= now`
 *    is never true for NULL). Orphaned from both ticks, not merely stuck.
 *
 * The claims are private, so these drive them by reflection. That is deliberate: the whole
 * point is the state the claim leaves behind *before* any work runs, which no public entry
 * point exposes.
 */
class CompetitionRoundClaimLeaseTest extends KernelTestCase
{
    use EntrantFixtureTrait;

    private EntityManagerInterface $em;
    private CompetitionDrawService $drawService;
    private CompetitionResultsService $resultsService;
    private CompetitionRoundRepository $roundRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em              = self::getContainer()->get(EntityManagerInterface::class);
        $this->drawService     = self::getContainer()->get(CompetitionDrawService::class);
        $this->resultsService  = self::getContainer()->get(CompetitionResultsService::class);
        $this->roundRepository = self::getContainer()->get(CompetitionRoundRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_entrant_squad, competition_fixture,
                      competition_result, competition_result_audit, competition_round,
                      competition_response_cache, active_competition, competition_template,
                      entrant_reward_claim CASCADE',
        );
    }

    private function club(string $name): Club
    {
        $user = new User(sprintf('lease-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);
        $this->em->persist($user);
        $this->em->persist($club);

        return $club;
    }

    private function lockedCompetition(int $capacity = 4): ActiveCompetition
    {
        $template = new CompetitionTemplate('Lease Cup', 'lease-cup-' . uniqid('', true), $capacity, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);
        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);

        for ($i = 1; $i <= $capacity; $i++) {
            $club = $this->club("Lease Club {$i}");
            $this->makeEntrantFromEnvelope($this->em, $instance, $club, 0, [
                'club'    => ['id' => (string) $club->getId(), 'name' => "Lease Club {$i}"],
                'players' => [],
            ]);
        }
        $this->em->flush();

        self::getContainer()->get(CompetitionLockService::class)->lock($instance);
        $this->em->flush();

        return $instance;
    }

    private function firstRound(ActiveCompetition $instance): CompetitionRound
    {
        return $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];
    }

    private function invokeClaim(object $service, string $method, CompetitionRound $round, \DateTimeImmutable $now): bool
    {
        $m = new \ReflectionMethod($service, $method);

        return (bool) $m->invoke($service, $round, $now);
    }

    private function setLockAge(CompetitionRound $round, string $column, ?string $modify): void
    {
        $this->em->getConnection()->executeStatement(
            sprintf('UPDATE competition_round SET %s = :value WHERE id = :id', $column),
            [
                'value' => $modify === null ? null : (new \DateTimeImmutable($modify))->format('Y-m-d H:i:s'),
                'id'    => $round->getId()->toRfc4122(),
            ],
        );
        $this->em->refresh($round);
    }

    /**
     * The orphaning fix, asserted directly: claiming must not move the status. Only
     * drawRound()'s own flush may, together with the fixtures the status refers to.
     */
    public function testClaimingADrawDoesNotYetMarkTheRoundDrawn(): void
    {
        $round = $this->firstRound($this->lockedCompetition());
        self::assertSame(CompetitionRoundStatus::DRAW_PENDING, $round->getStatus());

        self::assertTrue($this->invokeClaim($this->drawService, 'claimDraw', $round, new \DateTimeImmutable()));

        self::assertSame(
            CompetitionRoundStatus::DRAW_PENDING,
            $round->getStatus(),
            'the status must still be draw-pending — a crash now has to leave the round reclaimable',
        );
        self::assertNotNull($round->getDrawLockedAt(), 'the lease must be held');
    }

    public function testAFreshDrawClaimBlocksASecondClaim(): void
    {
        $round = $this->firstRound($this->lockedCompetition());
        $now   = new \DateTimeImmutable();

        self::assertTrue($this->invokeClaim($this->drawService, 'claimDraw', $round, $now));
        self::assertFalse(
            $this->invokeClaim($this->drawService, 'claimDraw', $round, $now),
            'a lease held by a running tick must not be stealable',
        );
    }

    public function testAStaleDrawClaimIsReclaimable(): void
    {
        $round = $this->firstRound($this->lockedCompetition());

        // Stand in for a tick that claimed the round and then died.
        $this->setLockAge($round, 'draw_locked_at', sprintf('-%d seconds', CompetitionRound::CLAIM_LEASE_SECONDS + 60));

        self::assertTrue(
            $this->invokeClaim($this->drawService, 'claimDraw', $round, new \DateTimeImmutable()),
            'a lease older than the window must be reclaimable, or a crashed tick strands the round forever',
        );
    }

    public function testASuccessfulDrawMarksTheRoundDrawnAndReleasesTheLease(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);

        $this->drawService->forceDrawRound($round);
        $this->em->refresh($round);

        self::assertSame(CompetitionRoundStatus::DRAWN, $round->getStatus());
        self::assertNull($round->getDrawLockedAt(), 'the lease must be released once the draw has committed');
        self::assertNotNull($round->getMatchesResolveAt(), 'a drawn round must be visible to findDueForResults()');
    }

    public function testAStaleResolveClaimIsReclaimableButAFreshOneIsNot(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);
        $this->drawService->forceDrawRound($round);
        $this->em->refresh($round);

        $now = new \DateTimeImmutable();
        self::assertTrue($this->invokeClaim($this->resultsService, 'claimResults', $round, $now));
        self::assertFalse(
            $this->invokeClaim($this->resultsService, 'claimResults', $round, $now),
            'a lease held by a running tick must not be stealable',
        );

        $this->setLockAge($round, 'resolve_locked_at', sprintf('-%d seconds', CompetitionRound::CLAIM_LEASE_SECONDS + 60));

        self::assertTrue(
            $this->invokeClaim($this->resultsService, 'claimResults', $round, new \DateTimeImmutable()),
            'a lease older than the window must be reclaimable',
        );
    }

    public function testPublishingResultsReleasesTheResolveLease(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->firstRound($instance);
        $this->drawService->forceDrawRound($round);
        $this->em->refresh($round);

        $this->resultsService->publishDueResults(new \DateTimeImmutable('+1 year'));
        $this->em->refresh($round);

        self::assertSame(CompetitionRoundStatus::RESULTS_PUBLISHED, $round->getStatus());
        self::assertNull($round->getResolveLockedAt(), 'the lease must be released once results have committed');
    }
}

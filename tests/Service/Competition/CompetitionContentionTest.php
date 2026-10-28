<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionDrawService;
use App\Service\Competition\CompetitionResponseBuilder;
use App\Service\Competition\CompetitionResponseCacheService;
use App\Service\Competition\CompetitionRegistrationService;
use App\Tests\Support\EntrantFixtureTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Contention, demonstrated across two real database connections rather than argued.
 *
 * Phase 4's benchmark bounds the work per message but says nothing about two workers meeting
 * on one competition, and the existing lease and cache tests all run on a single connection —
 * so the advisory lock and the claim guard were, in the plan's own words, "argued for, not
 * demonstrated". These close that, as far as a single PHP process can: a second DBAL
 * connection is a genuinely separate session, which is the level both mechanisms actually
 * operate at.
 *
 * What this still does not cover: true parallelism in time. The two connections interleave
 * under this process's control rather than racing. That is enough for both mechanisms here,
 * because each is enforced by Postgres at the statement level — a single predicated UPDATE
 * for the claim, and a session-scoped advisory lock for the rebuild — and neither depends on
 * PHP-side timing.
 */
class CompetitionContentionTest extends KernelTestCase
{
    use EntrantFixtureTrait;

    private EntityManagerInterface $em;
    private CompetitionRoundRepository $roundRepository;
    private ?Connection $second = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em              = self::getContainer()->get(EntityManagerInterface::class);
        $this->roundRepository = self::getContainer()->get(CompetitionRoundRepository::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, competition_entrant_squad, competition_fixture,
                      competition_result, competition_result_audit, competition_round,
                      competition_response_cache, active_competition, competition_template,
                      entrant_reward_claim CASCADE',
        );
    }

    protected function tearDown(): void
    {
        $this->second?->close();
        $this->second = null;
        parent::tearDown();
    }

    /** A genuinely separate session against the same database. */
    private function secondConnection(): Connection
    {
        return $this->second ??= DriverManager::getConnection(
            $this->em->getConnection()->getParams(),
        );
    }

    private function club(string $name): Club
    {
        $user = new User(sprintf('contention-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club($name, $user);
        $club->setBalance(10_000_000);
        $this->em->persist($user);
        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function lockedCompetition(int $capacity = 4): ActiveCompetition
    {
        $template = new CompetitionTemplate('Contention Cup', 'contention-' . uniqid('', true), $capacity, CompetitionDuration::TEN_HOURS);
        $this->em->persist($template);
        $instance = new ActiveCompetition($template);
        $this->em->persist($instance);
        $this->em->flush();

        $registration = self::getContainer()->get(CompetitionRegistrationService::class);
        for ($i = 1; $i <= $capacity; $i++) {
            $club     = $this->club("Contention Club {$i}");
            $players  = [];
            for ($p = 0; $p < 11; $p++) {
                $players[] = ['id' => "c{$i}-p{$p}", 'name' => "C{$i} P{$p}", 'position' => 'MID', 'currentAbility' => 60];
            }
            $registration->register($instance, $club, [
                'club'    => ['id' => (string) $club->getId(), 'name' => "Contention Club {$i}"],
                'players' => $players,
                'staff'   => [['id' => 's1', 'role' => 'MANAGER', 'name' => 'Boss']],
            ]);
        }
        $this->em->refresh($instance);

        return $instance;
    }

    /**
     * Two sessions cannot both claim one round.
     *
     * The guard is a single predicated UPDATE, so Postgres serialises the two row updates and
     * the loser simply matches nothing. Asserted from a separate session because that is the
     * level it has to hold at — a PHP-side check would prove nothing about two consumers.
     */
    public function testASecondSessionCannotClaimARoundAlreadyClaimed(): void
    {
        $instance = $this->lockedCompetition();
        $round    = $this->roundRepository->findByCompetitionOrderedByIndex($instance)[0];

        // Session one claims it, through the real service.
        $claim = new \ReflectionMethod(CompetitionDrawService::class, 'claimDraw');
        self::assertTrue($claim->invoke(
            self::getContainer()->get(CompetitionDrawService::class),
            $round,
            new \DateTimeImmutable(),
        ));

        // Session two attempts the identical claim. It must match zero rows.
        $affected = $this->secondConnection()->executeStatement(
            <<<'SQL'
            UPDATE competition_round
               SET draw_locked_at = :now
             WHERE id = :id
               AND status = :draw_pending
               AND (draw_locked_at IS NULL OR draw_locked_at < :reclaimBefore)
            SQL,
            [
                'now'           => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'            => $round->getId()->toRfc4122(),
                'draw_pending'  => 'draw_pending',
                'reclaimBefore' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            ],
        );

        self::assertSame(0, $affected, 'a second session must not be able to steal a live claim');
    }

    /**
     * The rebuild advisory lock genuinely excludes another session.
     *
     * This is the one the plan called argued-but-unproven. The lock is taken inside
     * CompetitionResponseCacheService::rebuild(), held only for the duration of the build, and
     * released in a finally — so the only honest place to observe it held is from inside the
     * builder callback, which is what this does.
     */
    public function testTheRebuildLockExcludesAnotherSessionWhileBuilding(): void
    {
        $instance = $this->lockedCompetition();
        $cache    = self::getContainer()->get(CompetitionResponseCacheService::class);
        $builder  = self::getContainer()->get(CompetitionResponseBuilder::class);
        $key      = sprintf('competition_response:%s', $instance->getId()->toRfc4122());

        $heldDuringBuild = null;

        $cache->rebuild($instance, $builder->stateFingerprint($instance), function () use ($key, &$heldDuringBuild, $builder, $instance): array {
            // From a separate session: try, do not block. False means session one holds it.
            $acquired = (bool) $this->secondConnection()->fetchOne(
                'SELECT pg_try_advisory_lock(hashtext(:key))',
                ['key' => $key],
            );
            if ($acquired) {
                $this->secondConnection()->executeStatement('SELECT pg_advisory_unlock(hashtext(:key))', ['key' => $key]);
            }
            $heldDuringBuild = !$acquired;

            return $builder->sharedDocument($instance);
        });

        self::assertTrue($heldDuringBuild, 'the rebuild lock must exclude another session while a build is in flight');

        // And it must be released afterwards, or the next rebuild would deadlock on itself.
        $afterwards = (bool) $this->secondConnection()->fetchOne(
            'SELECT pg_try_advisory_lock(hashtext(:key))',
            ['key' => $key],
        );
        self::assertTrue($afterwards, 'the lock must be released once the build finishes');
        $this->secondConnection()->executeStatement('SELECT pg_advisory_unlock(hashtext(:key))', ['key' => $key]);
    }

    /**
     * A second session's registration cannot slip past a full bracket.
     *
     * register() serialises on SELECT ... FOR UPDATE over the ActiveCompetition row. Asserted
     * across sessions: once the bracket is full and locked, the row a second session reads
     * under FOR UPDATE already says SCHEDULED, which is what makes its in-transaction guard
     * reject rather than rely on a status read taken before the transaction opened.
     */
    public function testASecondSessionSeesTheLockedStatusUnderRowLock(): void
    {
        $instance = $this->lockedCompetition();

        $status = $this->secondConnection()->fetchOne(
            'SELECT status FROM active_competition WHERE id = :id FOR UPDATE',
            ['id' => $instance->getId()->toRfc4122()],
        );

        self::assertSame(
            'scheduled',
            $status,
            'the committed row a second session locks must already show the bracket closed',
        );
    }
}

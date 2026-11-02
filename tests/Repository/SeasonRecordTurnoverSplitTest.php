<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\SeasonRecord;
use App\Entity\User;
use App\Repository\SeasonRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Table Turnover tile reads two figures where it used to read one.
 *
 * The split has to agree with the total the same refresh stores in `tableTurnover` — the
 * landing page shows the two numbers and GET /api/telemetry still publishes the total, so a
 * client adding them up must land on the published figure.
 */
class SeasonRecordTurnoverSplitTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SeasonRecordRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(SeasonRecordRepository::class);

        $this->em->getConnection()->executeStatement('TRUNCATE season_record CASCADE');
    }

    public function testPromotionsAndRelegationsAreCountedSeparatelyAndSumToTheTotal(): void
    {
        $league = $this->league();
        $this->record($league, promoted: true,  relegated: false);
        $this->record($league, promoted: true,  relegated: false);
        $this->record($league, promoted: false, relegated: true);
        $this->em->flush();

        $since = new \DateTimeImmutable('-7 days');
        $split = $this->repository->countTurnoverByDirectionSince($since);

        self::assertSame(2, $split['promotions']);
        self::assertSame(1, $split['relegations']);
        self::assertSame(
            $this->repository->countPromotionsAndRelegationsSince($since),
            $split['promotions'] + $split['relegations'],
            'the split must add up to the total the API still publishes',
        );
    }

    /**
     * A title is neither direction, and the old total already excluded it — the split has to
     * keep excluding it, or the tile would start counting champions as promotions.
     */
    public function testATitleWinCountsInNeitherDirection(): void
    {
        $league = $this->league();
        $this->record($league, promoted: false, relegated: false, finalPosition: 1);
        $this->em->flush();

        self::assertSame(
            ['promotions' => 0, 'relegations' => 0],
            $this->repository->countTurnoverByDirectionSince(new \DateTimeImmutable('-7 days')),
        );
    }

    /** The window is the point of the figure: older movement is not this week's churn. */
    public function testMovementOlderThanTheWindowIsExcluded(): void
    {
        $league = $this->league();
        $this->record($league, promoted: true, relegated: false, createdAt: new \DateTimeImmutable('-30 days'));
        $this->record($league, promoted: true, relegated: false);
        $this->em->flush();

        self::assertSame(1, $this->repository->countTurnoverByDirectionSince(new \DateTimeImmutable('-7 days'))['promotions']);
    }

    /** No rows at all must read 0/0, not fail on SUM()'s null. */
    public function testAnEmptyWindowReportsZeroInBothDirections(): void
    {
        self::assertSame(
            ['promotions' => 0, 'relegations' => 0],
            $this->repository->countTurnoverByDirectionSince(new \DateTimeImmutable('-7 days')),
        );
    }

    /**
     * Get-or-create: leagues are unique on (country, tier), and season_record is the only
     * table this class truncates — so a league another test left behind is still there, and
     * a fresh one would collide. Which league a record belongs to does not matter here;
     * countTurnoverByDirectionSince() does not filter by league.
     */
    private function league(): League
    {
        $existing = $this->em->getRepository(League::class)->findOneBy(['country' => 'EN', 'tier' => 2]);
        if ($existing !== null) {
            return $existing;
        }

        $league = new League('EN', 2, 'Turnover Test League');
        $this->em->persist($league);

        return $league;
    }

    private function record(
        League $league,
        bool $promoted,
        bool $relegated,
        int $finalPosition = 4,
        ?\DateTimeImmutable $createdAt = null,
    ): void {
        $user = new User(sprintf('turnover-%s@example.com', uniqid('', true)));
        $user->setPassword('x');
        $club = new Club('Turnover FC', $user);
        $this->em->persist($user);
        $this->em->persist($club);

        $record = new SeasonRecord(
            $club,
            $league,
            1,
            $finalPosition,
            38,
            20,
            10,
            8,
            60,
            40,
            70,
            $promoted,
            $relegated,
        );
        $this->em->persist($record);

        // createdAt is set by the constructor, so an out-of-window row has to be backdated
        // through the column directly.
        if ($createdAt !== null) {
            $this->em->flush();
            $this->em->getConnection()->executeStatement(
                'UPDATE season_record SET created_at = :at WHERE id = :id',
                ['at' => $createdAt->format('Y-m-d H:i:s'), 'id' => $record->getId()],
            );
        }
    }
}

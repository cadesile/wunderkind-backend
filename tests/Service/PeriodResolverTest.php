<?php

namespace App\Tests\Service;

use App\Enum\StatsPeriod;
use App\Service\PeriodResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

class PeriodResolverTest extends TestCase
{
    private function makeQueryBuilder(EntityManagerInterface $em): QueryBuilder
    {
        $qb = new QueryBuilder($em);
        $qb->select('t')->from('App\Entity\Transfer', 't');

        return $qb;
    }

    public function testAllAppliesNoFilter(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $qb = $this->makeQueryBuilder($em);

        (new PeriodResolver($em))->applyPeriodFilter($qb, StatsPeriod::ALL, 't', 'occurredAt', 'c');

        $this->assertNull($qb->getDQLPart('where'));
    }

    public function testWeekSetsSevenDayCutoff(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $qb = $this->makeQueryBuilder($em);

        (new PeriodResolver($em))->applyPeriodFilter($qb, StatsPeriod::WEEK, 't', 'occurredAt', 'c');

        $cutoff = $qb->getParameter('periodStart')->getValue();
        $expected = (new \DateTimeImmutable())->modify('-7 days');

        $this->assertInstanceOf(\DateTimeImmutable::class, $cutoff);
        $this->assertEqualsWithDelta($expected->getTimestamp(), $cutoff->getTimestamp(), 5);
    }

    public function testMonthSetsThirtyDayCutoff(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $qb = $this->makeQueryBuilder($em);

        (new PeriodResolver($em))->applyPeriodFilter($qb, StatsPeriod::MONTH, 't', 'occurredAt', 'c');

        $cutoff = $qb->getParameter('periodStart')->getValue();
        $expected = (new \DateTimeImmutable())->modify('-30 days');

        $this->assertInstanceOf(\DateTimeImmutable::class, $cutoff);
        $this->assertEqualsWithDelta($expected->getTimestamp(), $cutoff->getTimestamp(), 5);
    }

    public function testSeasonBuildsPerClubCorrelatedSubquery(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturnCallback(fn () => new QueryBuilder($em));

        $qb = $this->makeQueryBuilder($em);

        (new PeriodResolver($em))->applyPeriodFilter($qb, StatsPeriod::SEASON, 't', 'occurredAt', 'c');

        $dql = $qb->getDQL();

        $this->assertStringContainsString(
            'SELECT MAX(sr.createdAt) FROM App\Entity\SeasonRecord sr WHERE sr.club = c.id',
            $dql,
        );
        $this->assertStringContainsString(
            'NOT EXISTS (SELECT sr2.id FROM App\Entity\SeasonRecord sr2 WHERE sr2.club = c.id)',
            $dql,
        );
        $this->assertStringContainsString('t.occurredAt >=', $dql);
    }
}

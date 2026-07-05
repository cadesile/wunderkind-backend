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
            'SELECT MAX(__period_sr_max.createdAt) FROM App\Entity\SeasonRecord __period_sr_max WHERE __period_sr_max.club = c.id',
            $dql,
        );
        $this->assertStringContainsString(
            'NOT EXISTS (SELECT __period_sr_exists.id FROM App\Entity\SeasonRecord __period_sr_exists WHERE __period_sr_exists.club = c.id)',
            $dql,
        );
        $this->assertStringContainsString('t.occurredAt >=', $dql);
    }

    /**
     * Regression guard for a bug caught only via live end-to-end testing (not
     * by unit tests, since it only manifests when the outer query is itself a
     * SeasonRecord query aliased "sr" — e.g. SeasonRecordRepository::getMostSeasonsByClub()).
     * PeriodResolver's internal subqueries originally used plain "sr"/"sr2"
     * aliases, which Doctrine rejected with "[Semantical Error] ... 'sr' is
     * already defined" whenever the caller's own alias was also "sr".
     */
    public function testSeasonFilterDoesNotCollideWhenOuterAliasIsSr(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturnCallback(fn () => new QueryBuilder($em));

        $qb = new QueryBuilder($em);
        $qb->select('sr')->from('App\Entity\SeasonRecord', 'sr');

        (new PeriodResolver($em))->applyPeriodFilter($qb, StatsPeriod::SEASON, 'sr', 'createdAt', 'c');

        $dql = $qb->getDQL();
        preg_match_all('/FROM App\\\\Entity\\\\SeasonRecord (\w+)/', $dql, $matches);
        $aliases = $matches[1];

        $this->assertCount(3, $aliases, "Expected the outer query plus 2 internal subqueries to reference SeasonRecord.\nDQL: {$dql}");
        $this->assertSame(
            count($aliases),
            count(array_unique($aliases)),
            "PeriodResolver's internal subquery aliases collided with the outer query's alias.\nAliases found: ".implode(', ', $aliases)."\nDQL: {$dql}",
        );
    }
}

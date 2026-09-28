<?php

namespace App\Tests\Repository;

use App\Entity\League;
use App\Repository\LeagueRepository;
use PHPUnit\Framework\TestCase;

class LeagueRepositoryTest extends TestCase
{
    public function testFindLowestTierForCountryMethodExists(): void
    {
        $this->assertTrue(
            method_exists(LeagueRepository::class, 'findLowestTierForCountry'),
            'LeagueRepository::findLowestTierForCountry() must exist'
        );
    }

    public function testFindLowestTierForCountryReturnsNullWhenNoLeaguesFound(): void
    {
        $query = $this->createStub(\Doctrine\ORM\Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $qb = $this->createStub(\Doctrine\ORM\QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repo = $this->getStubBuilder(LeagueRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getStub();
        $repo->method('createQueryBuilder')->willReturn($qb);

        $result = $repo->findLowestTierForCountry('EN');
        $this->assertNull($result);
    }

    public function testFindLowestTierForCountryReturnsLeagueWhenFound(): void
    {
        $league = new League('EN', 8, 'League 8');

        $query = $this->createStub(\Doctrine\ORM\Query::class);
        $query->method('getOneOrNullResult')->willReturn($league);

        $qb = $this->createStub(\Doctrine\ORM\QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repo = $this->getStubBuilder(LeagueRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getStub();
        $repo->method('createQueryBuilder')->willReturn($qb);

        $result = $repo->findLowestTierForCountry('EN');
        $this->assertSame($league, $result);
        $this->assertSame(8, $result->getTier());
    }

    public function testFindLowestTierForCountryOrdersByTierDesc(): void
    {
        $query = $this->createStub(\Doctrine\ORM\Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $qb->expects($this->once())
            ->method('orderBy')
            ->with('l.tier', 'DESC')
            ->willReturnSelf();

        $repo = $this->getStubBuilder(LeagueRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getStub();
        $repo->method('createQueryBuilder')->willReturn($qb);

        $repo->findLowestTierForCountry('EN');
    }
}

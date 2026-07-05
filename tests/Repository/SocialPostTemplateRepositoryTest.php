<?php

namespace App\Tests\Repository;

use App\Repository\SocialPostTemplateRepository;
use PHPUnit\Framework\TestCase;

class SocialPostTemplateRepositoryTest extends TestCase
{
    public function testFindAllOrderedOrdersByCategoryThenPlatform(): void
    {
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $qb->expects($this->once())->method('orderBy')->with('t.category', 'ASC')->willReturnSelf();
        $qb->expects($this->once())->method('addOrderBy')->with('t.platform', 'ASC')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repo = $this->getMockBuilder(SocialPostTemplateRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();
        $repo->method('createQueryBuilder')->with('t')->willReturn($qb);

        $result = $repo->findAllOrdered();
        $this->assertSame([], $result);
    }
}

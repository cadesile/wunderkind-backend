<?php

namespace App\Tests\Repository;

use App\Entity\SocialPostTemplate;
use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
use App\Repository\SocialPostTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SocialPostTemplateRepositoryTest extends KernelTestCase
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

    public function testFindByCategoryAndPlatformFiltersByPeriod(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(SocialPostTemplateRepository::class);

        $weekTemplate = new SocialPostTemplate(StatCategory::BEST_FORM, SocialPlatform::FACEBOOK, StatsPeriod::WEEK, 'Week body.');
        $monthTemplate = new SocialPostTemplate(StatCategory::BEST_FORM, SocialPlatform::FACEBOOK, StatsPeriod::MONTH, 'Month body.');
        $em->persist($weekTemplate);
        $em->persist($monthTemplate);
        $em->flush();

        try {
            $foundWeek = $repository->findByCategoryAndPlatform(StatCategory::BEST_FORM, SocialPlatform::FACEBOOK, StatsPeriod::WEEK);
            $foundMonth = $repository->findByCategoryAndPlatform(StatCategory::BEST_FORM, SocialPlatform::FACEBOOK, StatsPeriod::MONTH);
            $foundMissing = $repository->findByCategoryAndPlatform(StatCategory::BEST_FORM, SocialPlatform::FACEBOOK, StatsPeriod::LAST_24_HOURS);

            $this->assertSame($weekTemplate->getId()->toRfc4122(), $foundWeek?->getId()->toRfc4122());
            $this->assertSame($monthTemplate->getId()->toRfc4122(), $foundMonth?->getId()->toRfc4122());
            $this->assertNull($foundMissing);
        } finally {
            $em->remove($weekTemplate);
            $em->remove($monthTemplate);
            $em->flush();
        }
    }

    public function testFindActiveByCategoryAndPlatformFiltersByPeriodAndActiveFlag(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(SocialPostTemplateRepository::class);

        $active = new SocialPostTemplate(StatCategory::BIGGEST_ROUT, SocialPlatform::TWITTER, StatsPeriod::WEEK, 'Active body.');
        $inactive = (new SocialPostTemplate(StatCategory::BIGGEST_ROUT, SocialPlatform::TWITTER, StatsPeriod::MONTH, 'Inactive body.'))
            ->setIsActive(false);
        $em->persist($active);
        $em->persist($inactive);
        $em->flush();

        try {
            $foundActive = $repository->findActiveByCategoryAndPlatform(StatCategory::BIGGEST_ROUT, SocialPlatform::TWITTER, StatsPeriod::WEEK);
            $foundInactive = $repository->findActiveByCategoryAndPlatform(StatCategory::BIGGEST_ROUT, SocialPlatform::TWITTER, StatsPeriod::MONTH);

            $this->assertSame($active->getId()->toRfc4122(), $foundActive?->getId()->toRfc4122());
            $this->assertNull($foundInactive);
        } finally {
            $em->remove($active);
            $em->remove($inactive);
            $em->flush();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PlayerArchetype;
use App\Enum\ArchetypePolarity;
use App\Service\ArchetypeShowcaseService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The landing page used to hard-code eight archetypes, so the shop window drifted
 * from the catalogue whenever one was renamed or reworded. These tests pin the
 * two properties that keep that from happening again: the sample comes from the
 * catalogue, and it is split by polarity.
 */
class ArchetypeShowcaseServiceTest extends KernelTestCase
{
    private function service(): ArchetypeShowcaseService
    {
        self::bootKernel();

        return self::getContainer()->get(ArchetypeShowcaseService::class);
    }

    public function testReturnsFourOfEachPolarityByDefault(): void
    {
        $sample = $this->service()->sample();

        self::assertCount(ArchetypeShowcaseService::SAMPLE_SIZE, $sample['positive']);
        self::assertCount(ArchetypeShowcaseService::SAMPLE_SIZE, $sample['negative']);
    }

    public function testEveryCardIsOnTheCorrectSideOfTheSplit(): void
    {
        $sample = $this->service()->sample();

        foreach ($sample['positive'] as $archetype) {
            self::assertSame(ArchetypePolarity::POSITIVE, $archetype->getPolarity());
        }
        foreach ($sample['negative'] as $archetype) {
            self::assertSame(ArchetypePolarity::NEGATIVE, $archetype->getPolarity());
        }
    }

    public function testTotalsDescribeTheCatalogueNotTheSample(): void
    {
        $sample = $this->service()->sample();

        self::assertGreaterThanOrEqual(count($sample['positive']), $sample['totalPositive']);
        self::assertGreaterThanOrEqual(count($sample['negative']), $sample['totalNegative']);
        self::assertSame($sample['totalPositive'] + $sample['totalNegative'], $sample['total']);
    }

    public function testSampleContainsNoDuplicates(): void
    {
        $sample = $this->service()->sample();

        foreach (['positive', 'negative'] as $polarity) {
            $slugs = array_map(static fn (PlayerArchetype $a): string => $a->getSlug(), $sample[$polarity]);
            self::assertSame(array_unique($slugs), $slugs, "{$polarity} sample repeats an archetype");
        }
    }

    /**
     * The point of reading from the catalogue is that the page changes between
     * visits. Ten choose four is 210 combinations, so twelve identical draws
     * would mean the shuffle is not happening.
     */
    public function testSampleRotatesBetweenCalls(): void
    {
        $service = $this->service();

        $seen = [];
        for ($i = 0; $i < 12; $i++) {
            $slugs = array_map(
                static fn (PlayerArchetype $a): string => $a->getSlug(),
                $service->sample()['positive'],
            );
            sort($slugs);
            $seen[implode('|', $slugs)] = true;
        }

        self::assertGreaterThan(1, count($seen), 'the sample never changed across 12 draws');
    }

    /** A grid row must not fail because the catalogue is short — it just fills less. */
    public function testAsksForMoreThanTheCatalogueHolds(): void
    {
        $sample = $this->service()->sample(500);

        self::assertCount($sample['totalPositive'], $sample['positive']);
        self::assertCount($sample['totalNegative'], $sample['negative']);
    }

    public function testZeroRequestedReturnsNothingRatherThanEverything(): void
    {
        $sample = $this->service()->sample(0);

        self::assertSame([], $sample['positive']);
        self::assertSame([], $sample['negative']);
    }
}

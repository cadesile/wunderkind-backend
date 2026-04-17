<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FixtureGenerationService;
use PHPUnit\Framework\TestCase;

class FixtureGenerationServiceTest extends TestCase
{
    private FixtureGenerationService $service;

    protected function setUp(): void
    {
        $this->service = new FixtureGenerationService();
    }

    public function testGenerateForFourClubs(): void
    {
        $clubIds = ['club1', 'club2', 'club3', 'club4'];
        $matchdays = $this->service->generate($clubIds);

        // Single round robin for 4 clubs should have 3 matchdays (N-1)
        // Double round robin should have 6 matchdays (2*(N-1))
        // The plan says "full season fixtures", which usually implies double round robin.
        // Let's assume double round robin.
        $this->assertCount(6, $matchdays);

        foreach ($matchdays as $matchday) {
            $this->assertCount(2, $matchday); // N/2 matches per matchday
        }

        // Verify all matchups occur twice (home and away)
        $matches = [];
        foreach ($matchdays as $matchday) {
            foreach ($matchday as $match) {
                $matches[] = $match;
            }
        }
        $this->assertCount(12, $matches);

        $counts = [];
        foreach ($matches as $match) {
            $key = $match[0] . ' vs ' . $match[1];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        // 4 clubs means 12 unique directed pairs
        $this->assertCount(12, $counts);
        foreach ($counts as $count) {
            $this->assertEquals(1, $count);
        }
    }

    public function testGenerateWithOddNumberOfClubs(): void
    {
        $clubIds = ['club1', 'club2', 'club3'];
        $matchdays = $this->service->generate($clubIds);

        // 3 clubs should be treated as 4 with a BYE
        // Double round robin: 6 matchdays
        $this->assertCount(6, $matchdays);

        foreach ($matchdays as $matchday) {
            // One match should be a real match, one should have BYE?
            // Actually, if we return only real matches, then 1 match per matchday.
            // Or maybe BYE is explicit.
            // Let's see how I handle BYEs.
        }
    }
}

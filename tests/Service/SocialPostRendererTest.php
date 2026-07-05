<?php

namespace App\Tests\Service;

use App\Entity\SocialPostTemplate;
use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
use App\Service\CommunityStatsService;
use App\Service\SocialPostRenderer;
use PHPUnit\Framework\TestCase;

class SocialPostRendererTest extends TestCase
{
    private function statsServiceReturning(string $method, StatsPeriod $expectedPeriod, array $rows): CommunityStatsService
    {
        $stats = $this->createMock(CommunityStatsService::class);
        $stats->expects($this->once())
            ->method($method)
            ->with($expectedPeriod, 1)
            ->willReturn($rows);

        return $stats;
    }

    public function testRendersAllTokensForMostTransfers(): void
    {
        $stats = $this->statsServiceReturning('getMostTransfers', StatsPeriod::WEEK, [
            ['clubId' => 'club-1', 'clubName' => 'Riverside FC', 'value' => 15, 'rank' => 1],
        ]);
        $renderer = new SocialPostRenderer($stats);

        $template = new SocialPostTemplate(
            StatCategory::MOST_TRANSFERS,
            SocialPlatform::FACEBOOK,
            StatsPeriod::WEEK,
            '{{clubName}} leads with {{value}} transfers this {{period}} (rank {{rank}}) — {{categoryLabel}}!',
        );

        $result = $renderer->render($template);

        $this->assertSame(
            'Riverside FC leads with 15 transfers this week (rank 1) — most transfers!',
            $result,
        );
    }

    public function testCategoryLabelMapsForAllFourCategories(): void
    {
        $cases = [
            ['method' => 'getMostTransfers',   'category' => StatCategory::MOST_TRANSFERS,   'label' => 'most transfers'],
            ['method' => 'getMostDevelopment', 'category' => StatCategory::MOST_DEVELOPMENT, 'label' => 'most player development'],
            ['method' => 'getMostSeasons',     'category' => StatCategory::MOST_SEASONS,     'label' => 'most seasons played'],
            ['method' => 'getMostTrophies',    'category' => StatCategory::MOST_TROPHIES,    'label' => 'most trophies won'],
        ];

        foreach ($cases as $case) {
            $stats = $this->statsServiceReturning($case['method'], StatsPeriod::ALL, [
                ['clubId' => 'club-1', 'clubName' => 'Test FC', 'value' => 3, 'rank' => 1],
            ]);
            $renderer = new SocialPostRenderer($stats);
            $template = new SocialPostTemplate($case['category'], SocialPlatform::TWITTER, StatsPeriod::ALL, '{{categoryLabel}}');

            $this->assertSame($case['label'], $renderer->render($template));
        }
    }

    public function testReturnsNullWhenLeaderboardIsEmpty(): void
    {
        $stats = $this->statsServiceReturning('getMostTrophies', StatsPeriod::ALL, []);
        $renderer = new SocialPostRenderer($stats);

        $template = new SocialPostTemplate(StatCategory::MOST_TROPHIES, SocialPlatform::FACEBOOK, StatsPeriod::ALL, '{{clubName}} won {{value}}!');

        $this->assertNull($renderer->render($template));
    }

    public function testUnmappedTokensAreLeftUntouched(): void
    {
        $stats = $this->statsServiceReturning('getMostSeasons', StatsPeriod::ALL, [
            ['clubId' => 'club-1', 'clubName' => 'Test FC', 'value' => 4, 'rank' => 1],
        ]);
        $renderer = new SocialPostRenderer($stats);

        $template = new SocialPostTemplate(StatCategory::MOST_SEASONS, SocialPlatform::FACEBOOK, StatsPeriod::ALL, '{{clubName}} and {{notARealToken}}');

        $this->assertSame('Test FC and {{notARealToken}}', $renderer->render($template));
    }
}

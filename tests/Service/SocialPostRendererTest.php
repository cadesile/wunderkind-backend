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

    /** Fills in the rank()-shape keys a real CommunityStatsService row always carries. */
    private function row(array $overrides): array
    {
        return $overrides + ['rank' => 1, 'playerName' => null, 'rawValue' => $overrides['value'] ?? 0, 'secondaryValue' => null];
    }

    public function testRendersAllTokensForMostTransfers(): void
    {
        $stats = $this->statsServiceReturning('getMostTransfers', StatsPeriod::WEEK, [
            $this->row(['clubId' => 'club-1', 'clubName' => 'Riverside FC', 'value' => 15]),
        ]);
        $renderer = new SocialPostRenderer($stats);

        $template = new SocialPostTemplate(
            StatCategory::MOST_TRANSFERS,
            SocialPlatform::FACEBOOK,
            StatsPeriod::WEEK,
            '{{clubName}} leads with {{value}} transfers {{period}} (rank {{rank}}) — {{categoryLabel}}!',
        );

        $result = $renderer->render($template);

        $this->assertSame(
            'Riverside FC leads with 15 transfers this week (rank 1) — most transfers!',
            $result,
        );
    }

    public function testCategoryLabelMapsForAllNineCategories(): void
    {
        $cases = [
            ['method' => 'getMostTransfers',   'category' => StatCategory::MOST_TRANSFERS,    'label' => 'most transfers'],
            ['method' => 'getMostDevelopment', 'category' => StatCategory::MOST_DEVELOPMENT,  'label' => 'most player development'],
            ['method' => 'getMostSeasons',     'category' => StatCategory::MOST_SEASONS,      'label' => 'most seasons played'],
            ['method' => 'getMostTrophies',    'category' => StatCategory::MOST_TROPHIES,     'label' => 'most trophies won'],
            ['method' => 'getBestForm',        'category' => StatCategory::BEST_FORM,         'label' => 'best current form'],
            ['method' => 'getBiggestRout',     'category' => StatCategory::BIGGEST_ROUT,      'label' => 'biggest win margin'],
            ['method' => 'getSuperStriker',    'category' => StatCategory::SUPER_STRIKER,     'label' => 'top goalscorer'],
            ['method' => 'getFortressDefence', 'category' => StatCategory::FORTRESS_DEFENCE,  'label' => 'best defensive record'],
            ['method' => 'getTransferSplurge', 'category' => StatCategory::TRANSFER_SPLURGE,  'label' => 'biggest transfer spend'],
        ];

        foreach ($cases as $case) {
            $stats = $this->statsServiceReturning($case['method'], StatsPeriod::ALL, [
                $this->row(['clubId' => 'club-1', 'clubName' => 'Test FC', 'value' => 3]),
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
            $this->row(['clubId' => 'club-1', 'clubName' => 'Test FC', 'value' => 4]),
        ]);
        $renderer = new SocialPostRenderer($stats);

        $template = new SocialPostTemplate(StatCategory::MOST_SEASONS, SocialPlatform::FACEBOOK, StatsPeriod::ALL, '{{clubName}} and {{notARealToken}}');

        $this->assertSame('Test FC and {{notARealToken}}', $renderer->render($template));
    }

    public function testRendersPlayerNameTokenForSuperStriker(): void
    {
        $stats = $this->statsServiceReturning('getSuperStriker', StatsPeriod::LAST_24_HOURS, [
            ['clubId' => 'club-1', 'clubName' => 'Test FC', 'value' => 4, 'rank' => 1, 'playerName' => 'J. Silva', 'rawValue' => 4, 'secondaryValue' => null],
        ]);
        $renderer = new SocialPostRenderer($stats);

        $template = new SocialPostTemplate(StatCategory::SUPER_STRIKER, SocialPlatform::FACEBOOK, StatsPeriod::LAST_24_HOURS, '{{playerName}} bagging {{statValue}} for {{clubName}}');

        $this->assertSame('J. Silva bagging 4 for Test FC', $renderer->render($template));
    }

    public function testPlayerNameTokenFallsBackToEmptyStringForClubOnlyCategories(): void
    {
        $stats = $this->statsServiceReturning('getMostTrophies', StatsPeriod::ALL, [
            $this->row(['clubId' => 'club-1', 'clubName' => 'Test FC', 'value' => 4]),
        ]);
        $renderer = new SocialPostRenderer($stats);

        $template = new SocialPostTemplate(StatCategory::MOST_TROPHIES, SocialPlatform::FACEBOOK, StatsPeriod::ALL, '[{{playerName}}]{{clubName}}');

        $this->assertSame('[]Test FC', $renderer->render($template));
    }

    public function testRendersSecondaryValueTokenForBestForm(): void
    {
        $stats = $this->statsServiceReturning('getBestForm', StatsPeriod::LAST_24_HOURS, [
            ['clubId' => 'club-1', 'clubName' => 'Test FC', 'value' => 5, 'rank' => 1, 'playerName' => null, 'rawValue' => 5, 'secondaryValue' => 8],
        ]);
        $renderer = new SocialPostRenderer($stats);

        $template = new SocialPostTemplate(StatCategory::BEST_FORM, SocialPlatform::FACEBOOK, StatsPeriod::LAST_24_HOURS, '+{{secondaryValue}} GD');

        $this->assertSame('+8 GD', $renderer->render($template));
    }

    public function testSecondaryValueTokenFallsBackToEmptyStringWhenNotApplicable(): void
    {
        $stats = $this->statsServiceReturning('getBiggestRout', StatsPeriod::WEEK, [
            $this->row(['clubId' => 'club-1', 'clubName' => 'Test FC', 'value' => 6]),
        ]);
        $renderer = new SocialPostRenderer($stats);

        $template = new SocialPostTemplate(StatCategory::BIGGEST_ROUT, SocialPlatform::FACEBOOK, StatsPeriod::WEEK, '[{{secondaryValue}}]');

        $this->assertSame('[]', $renderer->render($template));
    }

    public function testStatValueFormatsTransferSplurgeFromPenceToWholePounds(): void
    {
        $stats = $this->statsServiceReturning('getTransferSplurge', StatsPeriod::WEEK, [
            ['clubId' => 'club-1', 'clubName' => 'Test FC', 'value' => 1250000, 'rank' => 1, 'playerName' => null, 'rawValue' => 125000000, 'secondaryValue' => null],
        ]);
        $renderer = new SocialPostRenderer($stats);

        $template = new SocialPostTemplate(StatCategory::TRANSFER_SPLURGE, SocialPlatform::FACEBOOK, StatsPeriod::WEEK, '£{{statValue}}');

        $this->assertSame('£1,250,000', $renderer->render($template));
    }

    public function testStatValueFormatsFortressDefenceToOneDecimalPlace(): void
    {
        $stats = $this->statsServiceReturning('getFortressDefence', StatsPeriod::WEEK, [
            ['clubId' => 'club-1', 'clubName' => 'Test FC', 'value' => 1, 'rank' => 1, 'playerName' => null, 'rawValue' => 0.667, 'secondaryValue' => null],
        ]);
        $renderer = new SocialPostRenderer($stats);

        $template = new SocialPostTemplate(StatCategory::FORTRESS_DEFENCE, SocialPlatform::FACEBOOK, StatsPeriod::WEEK, '{{statValue}} per game');

        $this->assertSame('0.7 per game', $renderer->render($template));
    }

    public function testPeriodTokenRendersHumanReadablePhraseForEveryPeriod(): void
    {
        $expected = [
            [StatsPeriod::LAST_6_HOURS, 'in the last 6 hours'],
            [StatsPeriod::LAST_24_HOURS, 'over the last 24 hours'],
            [StatsPeriod::WEEK, 'this week'],
            [StatsPeriod::MONTH, 'this month'],
            [StatsPeriod::SEASON, 'this season'],
            [StatsPeriod::ALL, 'of all time'],
        ];

        foreach ($expected as [$period, $phrase]) {
            $stats = $this->statsServiceReturning('getMostTrophies', $period, [
                $this->row(['clubId' => 'club-1', 'clubName' => 'Test FC', 'value' => 1]),
            ]);
            $renderer = new SocialPostRenderer($stats);
            $template = new SocialPostTemplate(StatCategory::MOST_TROPHIES, SocialPlatform::FACEBOOK, $period, '{{period}}');

            $this->assertSame($phrase, $renderer->render($template), $period->value);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Entity\Club;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\League;
use App\Entity\User;
use App\Enum\Competition\CompetitionDuration;
use App\Repository\SeasonRecordRepository;
use App\Service\Competition\EligibilityEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class EligibilityEvaluatorTest extends TestCase
{
    private function makeClub(int $reputation, ?League $league = null): Club
    {
        $user = new User('elig@example.com');
        $club = new Club('Eligibility FC', $user);
        $club->setReputation($reputation);
        $club->setCurrentLeague($league);

        return $club;
    }

    private function makeEvaluator(int $seasonsCompleted = 0): EligibilityEvaluator
    {
        $seasonRecordRepository = $this->createMock(SeasonRecordRepository::class);
        $seasonRecordRepository->method('countByClub')->willReturn($seasonsCompleted);

        return new EligibilityEvaluator($seasonRecordRepository, new NullLogger());
    }

    public function testEligibleWhenAllConstraintsPass(): void
    {
        $template = new CompetitionTemplate('Cup', 'cup', 8, CompetitionDuration::ONE_DAY);
        $template->setMinClubReputation(10);

        $club = $this->makeClub(reputation: 50);

        $result = $this->makeEvaluator()->evaluate($template, $club);

        $this->assertTrue($result->eligible);
        $this->assertSame([], $result->reasons);
    }

    public function testAccumulatesEveryFailingReasonWithoutShortCircuiting(): void
    {
        $template = new CompetitionTemplate('Elite Cup', 'elite-cup', 8, CompetitionDuration::ONE_DAY);
        $template->setMinClubReputation(90);
        $template->setMinClubAgeSeasons(5);
        $template->setAllowedTiers([1]);

        // No league assigned -> no_league_assigned; reputation 0 < 90 -> min_reputation;
        // 0 completed seasons < 5 -> min_club_age. All three, not just the first.
        $club = $this->makeClub(reputation: 0);

        $result = $this->makeEvaluator(seasonsCompleted: 0)->evaluate($template, $club);

        $this->assertFalse($result->eligible);
        $this->assertSame(['no_league_assigned', 'min_reputation', 'min_club_age'], $result->reasons);
    }

    public function testTierSemanticsAreInvertedTier1IsElite(): void
    {
        $template = new CompetitionTemplate('Elite Cup', 'elite-cup-2', 8, CompetitionDuration::ONE_DAY);
        $template->setAllowedTiers([1]);

        $tier8League = new League('EN', 8, 'Sunday League');
        $club          = $this->makeClub(reputation: 0, league: $tier8League);

        $result = $this->makeEvaluator()->evaluate($template, $club);

        $this->assertFalse($result->eligible);
        $this->assertContains('tier_not_allowed', $result->reasons);
    }

    public function testMinClubAgeIsInertUnlessSetAboveZero(): void
    {
        $template = new CompetitionTemplate('Cup', 'cup-inert', 8, CompetitionDuration::ONE_DAY);
        // minClubAgeSeasons defaults to 0 -> the check must not fire even with 0 seasons completed.

        $club = $this->makeClub(reputation: 0);

        $result = $this->makeEvaluator(seasonsCompleted: 0)->evaluate($template, $club);

        $this->assertTrue($result->eligible);
    }
}

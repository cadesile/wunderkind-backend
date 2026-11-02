<?php

namespace App\Tests\Service;

use App\Entity\AudienceGroup;
use App\Entity\Club;
use App\Entity\League;
use App\Entity\User;
use App\Enum\AudienceCriteriaType;
use App\Service\AudienceCriteriaEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AudienceCriteriaEvaluatorTest extends TestCase
{
    private AudienceCriteriaEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AudienceCriteriaEvaluator(new NullLogger());
    }

    private function group(array|null $criteria, AudienceCriteriaType $type = AudienceCriteriaType::DYNAMIC): AudienceGroup
    {
        $group = new AudienceGroup('Test Group', 'test-group');
        $group->setCriteriaType($type);
        $group->setCriteriaPayload($criteria);

        return $group;
    }

    private function club(): Club
    {
        return new Club('Test FC', new User('criteria-test@example.com'));
    }

    public function testManualGroupNeverMatchesDynamically(): void
    {
        // Manual membership lives in AudienceGroupMember; this evaluator must not claim it.
        $group = $this->group(['minReputation' => 0], AudienceCriteriaType::MANUAL);

        $this->assertFalse($this->evaluator->matches($group, $this->club()));
    }

    public function testUnknownKeyMatchesNothing(): void
    {
        // Fails CLOSED: a typo must under-deliver, never broadcast to everyone.
        $club = $this->club();
        $club->setReputation(9999);

        $this->assertFalse($this->evaluator->matches($this->group(['minRepuation' => 0]), $club));
    }

    public function testUnknownKeyLosesEvenAlongsideAMatchingKey(): void
    {
        $club = $this->club();
        $club->setReputation(500);

        $group = $this->group(['minReputation' => 100, 'squadSize' => 20]);

        $this->assertFalse($this->evaluator->matches($group, $club));
    }

    public function testEmptyCriteriaMatchesNothing(): void
    {
        $this->assertFalse($this->evaluator->matches($this->group([]), $this->club()));
        $this->assertFalse($this->evaluator->matches($this->group(null), $this->club()));
    }

    public function testMinReputationIsInclusiveAtTheBoundary(): void
    {
        $club = $this->club();
        $club->setReputation(500);

        $this->assertTrue($this->evaluator->matches($this->group(['minReputation' => 500]), $club));
        $this->assertTrue($this->evaluator->matches($this->group(['minReputation' => 499]), $club));
        $this->assertFalse($this->evaluator->matches($this->group(['minReputation' => 501]), $club));
    }

    public function testMaxReputationIsInclusiveAtTheBoundary(): void
    {
        $club = $this->club();
        $club->setReputation(500);

        $this->assertTrue($this->evaluator->matches($this->group(['maxReputation' => 500]), $club));
        $this->assertTrue($this->evaluator->matches($this->group(['maxReputation' => 501]), $club));
        $this->assertFalse($this->evaluator->matches($this->group(['maxReputation' => 499]), $club));
    }

    public function testReputationRangeCombinesAsAnd(): void
    {
        $club = $this->club();
        $club->setReputation(500);

        $this->assertTrue($this->evaluator->matches(
            $this->group(['minReputation' => 100, 'maxReputation' => 1000]),
            $club,
        ));
        $this->assertFalse($this->evaluator->matches(
            $this->group(['minReputation' => 100, 'maxReputation' => 400]),
            $club,
        ));
    }

    public function testWeekBounds(): void
    {
        $club = $this->club();
        $club->setLastSyncedWeek(30);

        $this->assertTrue($this->evaluator->matches($this->group(['minWeek' => 30]), $club));
        $this->assertTrue($this->evaluator->matches($this->group(['maxWeek' => 30]), $club));
        $this->assertFalse($this->evaluator->matches($this->group(['minWeek' => 31]), $club));
        $this->assertFalse($this->evaluator->matches($this->group(['maxWeek' => 29]), $club));
    }

    public function testCountryAcceptsScalarOrList(): void
    {
        $club = $this->club();
        $club->setCountry('EN');

        $this->assertTrue($this->evaluator->matches($this->group(['country' => 'EN']), $club));
        $this->assertTrue($this->evaluator->matches($this->group(['country' => ['IT', 'EN']]), $club));
        $this->assertFalse($this->evaluator->matches($this->group(['country' => 'IT']), $club));
    }

    public function testCountryCriterionExcludesClubWithoutCountry(): void
    {
        $this->assertFalse($this->evaluator->matches($this->group(['country' => 'EN']), $this->club()));
    }

    public function testSetupCompletedBothWays(): void
    {
        $done = $this->club();
        $done->setTutorialCompletedAt(new \DateTimeImmutable());

        $notDone = $this->club();

        $this->assertTrue($this->evaluator->matches($this->group(['setupCompleted' => true]), $done));
        $this->assertFalse($this->evaluator->matches($this->group(['setupCompleted' => false]), $done));

        $this->assertTrue($this->evaluator->matches($this->group(['setupCompleted' => false]), $notDone));
        $this->assertFalse($this->evaluator->matches($this->group(['setupCompleted' => true]), $notDone));
    }

    /**
     * The retired spelling must keep working, and must mean the same thing.
     *
     * This is the test that matters more than the rename itself. The whitelist fails CLOSED,
     * so without the alias a stored group carrying `tutorialCompleted` would match NOBODY —
     * and because that is the safe direction by design, nothing would report it. A group that
     * used to reach most of the player base (`tutorialCompleted: false`) would simply stop
     * delivering, silently, on deploy.
     */
    public function testRetiredTutorialCompletedKeyStillEvaluatesAsSetupCompleted(): void
    {
        $done = $this->club();
        $done->setTutorialCompletedAt(new \DateTimeImmutable());

        $notDone = $this->club();

        foreach ([true, false] as $value) {
            $this->assertSame(
                $this->evaluator->matches($this->group(['setupCompleted' => $value]), $done),
                $this->evaluator->matches($this->group(['tutorialCompleted' => $value]), $done),
                'the alias must agree with its replacement on a completed club',
            );
            $this->assertSame(
                $this->evaluator->matches($this->group(['setupCompleted' => $value]), $notDone),
                $this->evaluator->matches($this->group(['tutorialCompleted' => $value]), $notDone),
                'the alias must agree with its replacement on an unfinished club',
            );
        }

        // Not merely "does not crash": it must actually select.
        $this->assertTrue($this->evaluator->matches($this->group(['tutorialCompleted' => true]), $done));
        $this->assertTrue($this->evaluator->matches($this->group(['tutorialCompleted' => false]), $notDone));
    }

    public function testRetiredKeyIsNotAdvertisedAsSupported(): void
    {
        // Admin help lists SUPPORTED_KEYS verbatim, so a legacy key leaking in there would
        // recommend the spelling we are retiring.
        $this->assertNotContains('tutorialCompleted', AudienceCriteriaEvaluator::SUPPORTED_KEYS);
        $this->assertContains('setupCompleted', AudienceCriteriaEvaluator::SUPPORTED_KEYS);
        $this->assertArrayHasKey('tutorialCompleted', AudienceCriteriaEvaluator::LEGACY_KEYS);

        // Every legacy key must point at a key that really exists, or the alias is a slow
        // trapdoor into the fails-closed path it was added to avoid.
        foreach (AudienceCriteriaEvaluator::LEGACY_KEYS as $legacy => $replacement) {
            $this->assertContains(
                $replacement,
                AudienceCriteriaEvaluator::SUPPORTED_KEYS,
                sprintf('LEGACY_KEYS maps %s to %s, which is not supported.', $legacy, $replacement),
            );
        }
    }

    public function testLeagueTierMatchesScalarAndList(): void
    {
        $club = $this->club();
        $club->setCurrentLeague($this->league(8));

        // Tier is inverted: 8 is where new clubs start, 1 is the top division.
        $this->assertTrue($this->evaluator->matches($this->group(['leagueTier' => 8]), $club));
        $this->assertTrue($this->evaluator->matches($this->group(['leagueTier' => [7, 8]]), $club));
        $this->assertFalse($this->evaluator->matches($this->group(['leagueTier' => 1]), $club));
    }

    public function testLeagueTierExcludesClubWithNoLeagueRatherThanThrowing(): void
    {
        // A club with no country never gets a league (SyncService::maybeAutoAssignLeague).
        $club = $this->club();
        $this->assertNull($club->getCurrentLeague());

        $this->assertFalse($this->evaluator->matches($this->group(['leagueTier' => 8]), $club));
    }

    public function testEverySupportedKeyHasABranch(): void
    {
        // A key listed in SUPPORTED_KEYS but missing from the match() arms would fall through
        // to `default => false`. Each key here is given a value the club satisfies, so any
        // key without a real branch shows up as a failure.
        $club = $this->club();
        $club->setReputation(500);
        $club->setLastSyncedWeek(10);
        $club->setCountry('EN');
        $club->setTutorialCompletedAt(new \DateTimeImmutable());
        $club->setCurrentLeague($this->league(8));

        $satisfied = [
            'minReputation'     => 1,
            'maxReputation'     => 10000,
            'country'           => 'EN',
            'leagueTier'        => 8,
            'minWeek'           => 1,
            'maxWeek'           => 100,
            'setupCompleted'    => true,
        ];

        $this->assertSame(
            AudienceCriteriaEvaluator::SUPPORTED_KEYS,
            array_keys($satisfied),
            'SUPPORTED_KEYS changed — add the new key to this fixture and give it a match() branch.',
        );

        foreach ($satisfied as $key => $value) {
            $this->assertTrue(
                $this->evaluator->matches($this->group([$key => $value]), $club),
                sprintf('Criterion "%s" has no matching branch in matchesCriterion().', $key),
            );
        }
    }

    private function league(int $tier): League
    {
        return new League('EN', $tier, sprintf('Tier %d', $tier));
    }
}

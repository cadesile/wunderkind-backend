<?php
// tests/Service/PlayerGenerationServiceTest.php
namespace App\Tests\Service;

use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Service\NameGeneratorService;
use App\Service\PlayerGenerationService;
use PHPUnit\Framework\TestCase;

class PlayerGenerationServiceTest extends TestCase
{
    private function makeService(): PlayerGenerationService
    {
        $nameGen = $this->createMock(NameGeneratorService::class);
        $nameGen->method('getRandomNationality')->willReturn('English');
        $nameGen->method('generatePlayerName')->willReturn([
            'firstName' => 'Test',
            'lastName'  => 'Player',
        ]);
        return new PlayerGenerationService($nameGen);
    }

    public function testGenerateReturnsPlayerInstance(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
        $this->assertInstanceOf(Player::class, $player);
    }

    public function testGeneratedPlayerHasCorrectPosition(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::GOALKEEPER, RecruitmentSource::SCOUTING_NETWORK);
        $this->assertSame(PlayerPosition::GOALKEEPER, $player->getPosition());
    }

    public function testGeneratedPlayerHasCorrectRecruitmentSource(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::ATTACKER, RecruitmentSource::AGENT_OFFER);
        $this->assertSame(RecruitmentSource::AGENT_OFFER, $player->getRecruitmentSource());
    }

    public function testGeneratedPlayerHasNameFromNameGenerator(): void
    {
        $player = $this->makeService()->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
        $this->assertSame('Test', $player->getFirstName());
        $this->assertSame('Player', $player->getLastName());
        $this->assertSame('English', $player->getNationality());
    }

    public function testPotentialIsWithinRange(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertGreaterThanOrEqual(1, $player->getPotential());
            $this->assertLessThanOrEqual(100, $player->getPotential());
        }
    }

    public function testAgeIsWithinRange(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::DEFENDER, RecruitmentSource::SCOUTING_NETWORK);
            $age    = (int) $player->getDateOfBirth()->diff(new \DateTimeImmutable())->y;
            $this->assertGreaterThanOrEqual(16, $age);
            $this->assertLessThanOrEqual(33, $age);
        }
    }

    public function testHeightIsWithinRange(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertGreaterThanOrEqual(163, $player->getHeight());
            $this->assertLessThanOrEqual(211, $player->getHeight());
        }
    }

    public function testGoalkeeperReceivesHeightBias(): void
    {
        $svc     = $this->makeService();
        $gkHeights  = [];
        $midHeights = [];
        for ($i = 0; $i < 50; $i++) {
            $gkHeights[]  = $svc->generate(PlayerPosition::GOALKEEPER, RecruitmentSource::SCOUTING_NETWORK)->getHeight();
            $midHeights[] = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK)->getHeight();
        }
        $this->assertGreaterThan(array_sum($midHeights) / count($midHeights),
                                 array_sum($gkHeights)  / count($gkHeights),
                                 'GK average height should exceed MID average height');
    }

    public function testWeightIsWithinRange(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::GOALKEEPER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertGreaterThanOrEqual(60, $player->getWeight());
            $this->assertLessThanOrEqual(97, $player->getWeight());
        }
    }

    public function testForcedNationalityIsRespected(): void
    {
        $nameGen = $this->createMock(NameGeneratorService::class);
        $nameGen->method('getRandomNationality')->willReturn('English');
        $nameGen->method('generatePlayerName')->willReturn(['firstName' => 'Carlos', 'lastName' => 'Ruiz']);
        $svc = new PlayerGenerationService($nameGen);

        $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK, 'Spanish');
        $this->assertSame('Spanish', $player->getNationality());
    }

    public function testCurrentAbilityNeverExceedsPotential(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 50; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertLessThanOrEqual(
                $player->getPotential(),
                $player->getCurrentAbility(),
                "currentAbility {$player->getCurrentAbility()} must not exceed potential {$player->getPotential()}"
            );
        }
    }

    public function testYoungPlayersHaveLowerAbilityRatio(): void
    {
        // Players aged 16–21 should average <= 60% of their potential for currentAbility
        // (ability target caps at 60% for youth bracket)
        $svc   = $this->makeService();
        $ratios = [];
        for ($i = 0; $i < 100; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $age    = (int) $player->getDateOfBirth()->diff(new \DateTimeImmutable())->y;
            if ($age >= 16 && $age <= 21 && $player->getPotential() > 0) {
                $ratios[] = $player->getCurrentAbility() / $player->getPotential();
            }
        }
        if (count($ratios) >= 5) {
            $avgRatio = array_sum($ratios) / count($ratios);
            $this->assertLessThanOrEqual(0.65, $avgRatio,
                'Youth players (16–21) should average <= 65% ability/potential ratio');
        } else {
            $this->markTestSkipped('Too few youth players generated; re-run for a statistically meaningful sample.');
        }
    }

    public function testAllPersonalityTraitsAreWithin1To20(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $p      = $player->getPersonality();
            foreach ([
                $p->getDetermination(), $p->getProfessionalism(), $p->getAmbition(),
                $p->getLoyalty(), $p->getAdaptability(), $p->getPressure(),
                $p->getTemperament(), $p->getConsistency(),
            ] as $trait) {
                $this->assertGreaterThanOrEqual(1, $trait);
                $this->assertLessThanOrEqual(20, $trait);
            }
        }
    }

    public function testPersonalityTraitsCeilingRespectsPotential(): void
    {
        // Each trait must be <= ceil(20 * potential/100)
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $p      = $player->getPersonality();
            $maxTrait = (int) ceil(20 * $player->getPotential() / 100);
            foreach ([
                $p->getDetermination(), $p->getProfessionalism(), $p->getAmbition(),
                $p->getLoyalty(), $p->getAdaptability(), $p->getPressure(),
                $p->getTemperament(), $p->getConsistency(),
            ] as $trait) {
                $this->assertLessThanOrEqual(
                    $maxTrait, $trait,
                    "Trait {$trait} must not exceed ceil(20 * {$player->getPotential()} / 100) = {$maxTrait}"
                );
            }
        }
    }

    public function testCurrentAbilityIsAverageOfSixAttributes(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player   = $svc->generate(PlayerPosition::MIDFIELDER, RecruitmentSource::SCOUTING_NETWORK);
            $expected = (int) round((
                $player->getPace() + $player->getTechnical() + $player->getVision() +
                $player->getPower() + $player->getStamina() + $player->getHeart()
            ) / 6);
            $this->assertSame($expected, $player->getCurrentAbility(),
                'currentAbility must equal round(sum of 6 attributes / 6)');
        }
    }

    public function testAllAttributesAreAtLeastOne(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::ATTACKER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertGreaterThanOrEqual(1, $player->getPace());
            $this->assertGreaterThanOrEqual(1, $player->getTechnical());
            $this->assertGreaterThanOrEqual(1, $player->getVision());
            $this->assertGreaterThanOrEqual(1, $player->getPower());
            $this->assertGreaterThanOrEqual(1, $player->getStamina());
            $this->assertGreaterThanOrEqual(1, $player->getHeart());
        }
    }

    public function testAllAttributesAreAtMost100(): void
    {
        $svc = $this->makeService();
        for ($i = 0; $i < 30; $i++) {
            $player = $svc->generate(PlayerPosition::GOALKEEPER, RecruitmentSource::SCOUTING_NETWORK);
            $this->assertLessThanOrEqual(100, $player->getPace());
            $this->assertLessThanOrEqual(100, $player->getTechnical());
            $this->assertLessThanOrEqual(100, $player->getVision());
            $this->assertLessThanOrEqual(100, $player->getPower());
            $this->assertLessThanOrEqual(100, $player->getStamina());
            $this->assertLessThanOrEqual(100, $player->getHeart());
        }
    }

    public function testAttackerPaceAveragesHigherThanGkPace(): void
    {
        $svc     = $this->makeService();
        $attPace = [];
        $gkPace  = [];
        for ($i = 0; $i < 60; $i++) {
            $attPace[] = $svc->generate(PlayerPosition::ATTACKER,   RecruitmentSource::SCOUTING_NETWORK)->getPace();
            $gkPace[]  = $svc->generate(PlayerPosition::GOALKEEPER, RecruitmentSource::SCOUTING_NETWORK)->getPace();
        }
        $this->assertGreaterThan(
            array_sum($gkPace) / count($gkPace),
            array_sum($attPace) / count($attPace),
            'ATT average pace should exceed GK average pace'
        );
    }
}

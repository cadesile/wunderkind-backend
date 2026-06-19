<?php
// tests/Dto/PlayerBlueprintTest.php
namespace App\Tests\Dto;

use App\Dto\PlayerBlueprint;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use PHPUnit\Framework\TestCase;

class PlayerBlueprintTest extends TestCase
{
    public function testCanConstructWithAnchorsOnly(): void
    {
        $dob = new \DateTimeImmutable('2005-06-15');
        $bp  = new PlayerBlueprint(
            firstName:   'Test',
            lastName:    'Player',
            nationality: 'English',
            age:         19,
            dateOfBirth: $dob,
            height:      180,
            weight:      75,
            position:    PlayerPosition::MIDFIELDER,
            potential:   70,
            source:      RecruitmentSource::SCOUTING_NETWORK,
        );

        $this->assertSame('Test', $bp->firstName);
        $this->assertSame('Player', $bp->lastName);
        $this->assertSame('English', $bp->nationality);
        $this->assertSame(19, $bp->age);
        $this->assertSame($dob, $bp->dateOfBirth);
        $this->assertSame(180, $bp->height);
        $this->assertSame(75, $bp->weight);
        $this->assertSame(PlayerPosition::MIDFIELDER, $bp->position);
        $this->assertSame(70, $bp->potential);
        $this->assertSame(0.0, $bp->abilityTarget);
        $this->assertFalse($bp->isProdigy);
        $this->assertSame(0, $bp->determination);
        $this->assertSame(0, $bp->pace);
        $this->assertSame(0, $bp->currentAbility);
    }

    public function testCanEnrichWithNamedArgumentSpread(): void
    {
        $bp       = $this->makeBlueprint();
        $enriched = new PlayerBlueprint(...array_replace((array) $bp, ['abilityTarget' => 0.55, 'isProdigy' => false]));

        $this->assertSame(0.55, $enriched->abilityTarget);
        $this->assertFalse($enriched->isProdigy);
        $this->assertSame($bp->firstName, $enriched->firstName);
        $this->assertSame($bp->potential, $enriched->potential);
    }

    private function makeBlueprint(): PlayerBlueprint
    {
        return new PlayerBlueprint(
            firstName:   'A',
            lastName:    'B',
            nationality: 'Spanish',
            age:         20,
            dateOfBirth: new \DateTimeImmutable('2004-01-01'),
            height:      175,
            weight:      70,
            position:    PlayerPosition::ATTACKER,
            potential:   80,
            source:      RecruitmentSource::SCOUTING_NETWORK,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PersonalityProfile;
use App\Entity\PlayerArchetype;
use App\Enum\ArchetypePolarity;
use App\Repository\PlayerArchetypeRepository;
use App\Service\ArchetypeResolverService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the server-side mirror of the client's dual-archetype scoring against a
 * controlled catalogue. The documented formula (docs/frontend-integration.md) is:
 *
 *   norm(t) = (personality[t] / 20) * 100
 *   score   = SUM( w > 0 ? w * norm(t) : |w| * (100 - norm(t)) )
 *   matches = score >= threshold
 */
class ArchetypeResolverServiceTest extends TestCase
{
    private function service(PlayerArchetype ...$catalogue): ArchetypeResolverService
    {
        $repo = $this->createStub(PlayerArchetypeRepository::class);
        $repo->method('findBy')->willReturn($catalogue);

        return new ArchetypeResolverService($repo);
    }

    private function profile(array $traits): PersonalityProfile
    {
        $profile = new PersonalityProfile();
        foreach ($traits as $trait => $value) {
            $profile->{'set' . ucfirst($trait)}($value);
        }

        return $profile;
    }

    private function archetype(string $slug, ArchetypePolarity $polarity, array $formula, float $threshold): PlayerArchetype
    {
        return new PlayerArchetype($slug, ucfirst($slug), "The {$slug}.", $polarity, [
            'formula'   => $formula,
            'threshold' => $threshold,
        ]);
    }

    public function testPositiveWeightsScoreTheTraitDirectly(): void
    {
        $service = $this->service(
            $this->archetype('driven', ArchetypePolarity::POSITIVE, ['determination' => 0.5, 'professionalism' => 0.5], 65),
        );

        // norm(20) = 100 → 0.5*100 + 0.5*100 = 100
        $resolved = $service->resolve($this->profile(['determination' => 20, 'professionalism' => 20]));

        $this->assertSame(100.0, $resolved['positive']['score']);
        $this->assertTrue($resolved['positive']['matched']);
        $this->assertNull($resolved['negative'], 'no negative archetype in the catalogue');
    }

    public function testNegativeWeightsScoreTheInverseOfTheTrait(): void
    {
        $service = $this->service(
            $this->archetype('hothead', ArchetypePolarity::NEGATIVE, ['temperament' => -1.0], 60),
        );

        // norm(1) = 5 → 1.0 * (100 - 5) = 95
        $resolved = $service->resolve($this->profile(['temperament' => 1]));

        $this->assertSame(95.0, $resolved['negative']['score']);
        $this->assertTrue($resolved['negative']['matched']);
    }

    public function testHighestScorerWinsWithinEachPolarity(): void
    {
        $service = $this->service(
            $this->archetype('loyal', ArchetypePolarity::POSITIVE, ['loyalty' => 1.0], 50),
            $this->archetype('driven', ArchetypePolarity::POSITIVE, ['determination' => 1.0], 50),
            $this->archetype('lazy', ArchetypePolarity::NEGATIVE, ['professionalism' => -1.0], 50),
        );

        $resolved = $service->resolve($this->profile([
            'loyalty' => 4, 'determination' => 18, 'professionalism' => 2,
        ]));

        $this->assertSame('Driven', $resolved['positive']['name']);
        $this->assertSame('Lazy', $resolved['negative']['name']);
    }

    /**
     * A near-miss must still surface — the panel shows the closest match with
     * matched=false rather than rendering an empty card.
     */
    public function testClosestMatchIsReturnedEvenBelowThreshold(): void
    {
        $service = $this->service(
            $this->archetype('driven', ArchetypePolarity::POSITIVE, ['determination' => 1.0], 90),
        );

        // norm(10) = 50, threshold 90
        $resolved = $service->resolve($this->profile(['determination' => 10]));

        $this->assertSame(50.0, $resolved['positive']['score']);
        $this->assertSame(90.0, $resolved['positive']['threshold']);
        $this->assertFalse($resolved['positive']['matched']);
    }

    public function testEmptyCatalogueResolvesToNothing(): void
    {
        $resolved = $this->service()->resolve(new PersonalityProfile());

        $this->assertNull($resolved['positive']);
        $this->assertNull($resolved['negative']);
    }
}

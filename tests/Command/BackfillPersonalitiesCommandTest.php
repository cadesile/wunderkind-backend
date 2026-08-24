<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BackfillPersonalitiesCommand;
use App\Entity\PersonalityProfile;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\StaffRole;
use App\Service\Personality\PersonalityContext;
use App\Service\Personality\PersonalityGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;

class BackfillPersonalitiesCommandTest extends TestCase
{
    private function command(): BackfillPersonalitiesCommand
    {
        return new BackfillPersonalitiesCommand(
            $this->createStub(EntityManagerInterface::class),
            new PersonalityGeneratorService(),
        );
    }

    public function testCommandNameIsConfigured(): void
    {
        $ref  = new \ReflectionClass(BackfillPersonalitiesCommand::class);
        $attr = $ref->getAttributes(AsCommand::class);

        $this->assertNotEmpty($attr);
        $this->assertSame('app:backfill-personalities', $attr[0]->newInstance()->name);
    }

    public function testDryRunOptionIsAvailable(): void
    {
        $this->assertTrue($this->command()->getDefinition()->hasOption('dry-run'));
    }

    // ── The sentinel this command keys off ───────────────────────────────────

    public function testFreshProfileIsDefaultAndSoQualifiesForBackfill(): void
    {
        $this->assertTrue((new PersonalityProfile())->isDefault());
    }

    public function testGeneratedProfileNoLongerReadsAsDefault(): void
    {
        // Guards the whole point of the command: a backfilled row must stop
        // matching the selection predicate, or a second run would re-roll it.
        $profile = new PersonalityProfile();
        (new PersonalityGeneratorService())->apply($profile, PersonalityContext::forPlayer(18));

        $this->assertFalse($profile->isDefault());
    }

    public function testAnAlreadyPopulatedProfileIsLeftAlone(): void
    {
        $profile = new PersonalityProfile();
        $profile->setDetermination(17);

        // Not default → never selected by findDefaultProfiles, so its real values
        // survive. This is what stops the backfill trampling generated data.
        $this->assertFalse($profile->isDefault());
        $this->assertSame(17, $profile->getDetermination());
    }

    // ── Context selection per entity type ────────────────────────────────────

    public function testContextMatchesTheEntityType(): void
    {
        $contextFor = new \ReflectionMethod(BackfillPersonalitiesCommand::class, 'contextFor');
        $contextFor->setAccessible(true);
        $cmd = $this->command();

        foreach ([new Player(), new Staff('A', 'B', StaffRole::COACH), new Scout('S')] as $entity) {
            $this->assertInstanceOf(
                PersonalityContext::class,
                $contextFor->invoke($cmd, $entity),
                sprintf('%s should resolve a context', $entity::class),
            );
        }
    }

    public function testScoutContextAppliesItsTraitFloors(): void
    {
        // forScout() floors adaptability at 13 and consistency at 12 — proof the
        // backfill reuses the same role-aware generation as prePersist, rather
        // than rolling a flat matrix.
        $gen = new PersonalityGeneratorService();

        for ($i = 0; $i < 50; $i++) {
            $traits = $gen->rollTraits(PersonalityContext::forScout());
            $this->assertGreaterThanOrEqual(13, $traits['adaptability']);
            $this->assertGreaterThanOrEqual(12, $traits['consistency']);
        }
    }

    public function testBackfilledPlayersDoNotAllShareOneMatrix(): void
    {
        // The bug this command exists to prevent: identical matrices score
        // identically against every archetype, so a whole pool shares one pair.
        $gen  = new PersonalityGeneratorService();
        $seen = [];

        for ($i = 0; $i < 40; $i++) {
            $seen[] = implode(',', $gen->rollTraits(PersonalityContext::forPlayer(18)));
        }

        $this->assertGreaterThan(1, count(array_unique($seen)));
    }
}

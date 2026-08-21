<?php

namespace App\Tests\Entity;

use App\Entity\PersonalityProfile;
use App\Entity\PlayerArchetype;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression guard for the bug that made every player's archetype resolve to null.
 *
 * The pre-2026-08 catalogue weighted `bravery`, `ego` and `confidence` — none of which are
 * fields of PersonalityProfile. Those terms scored zero, so thresholds could never be met.
 * Nothing validated the vocabulary, so the breakage was silent for months.
 *
 * The valid trait list is read by reflection off PersonalityProfile rather than hardcoded, so
 * renaming a trait fails this test instead of quietly re-breaking classification.
 */
class PlayerArchetypeTraitVocabularyTest extends KernelTestCase
{
    /** @return string[] */
    private function validTraits(): array
    {
        $props = (new \ReflectionClass(PersonalityProfile::class))->getProperties();

        return array_map(fn (\ReflectionProperty $p) => $p->getName(), $props);
    }

    /** @return PlayerArchetype[] */
    private function seededArchetypes(): array
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester      = new CommandTester($application->find('app:seed-archetypes'));
        $tester->execute([]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(PlayerArchetype::class)->findAll();
    }

    public function testPersonalityProfileStillExposesTheEightTraits(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'determination', 'professionalism', 'ambition', 'loyalty',
                'adaptability', 'pressure', 'temperament', 'consistency',
            ],
            $this->validTraits(),
            'The archetype trait vocabulary is derived from PersonalityProfile. If this list '
            . 'changed, update the PlayerArchetype docblock, the admin help text and the seeder.',
        );
    }

    public function testEveryFormulaKeyIsARealPersonalityTrait(): void
    {
        $valid = $this->validTraits();

        foreach ($this->seededArchetypes() as $archetype) {
            $formula = $archetype->getTraitWeights()['formula'] ?? null;

            $this->assertIsArray($formula, sprintf('"%s" has no formula.', $archetype->getSlug()));
            $this->assertNotEmpty($formula, sprintf('"%s" has an empty formula.', $archetype->getSlug()));

            foreach (array_keys($formula) as $trait) {
                $this->assertContains(
                    $trait,
                    $valid,
                    sprintf(
                        'Archetype "%s" weights "%s", which is not a PersonalityProfile trait. '
                        . 'It would always score 0 and the archetype could never match.',
                        $archetype->getSlug(),
                        $trait,
                    ),
                );
            }
        }
    }

    public function testAbsoluteWeightsSumToOneAndThresholdIsInRange(): void
    {
        foreach ($this->seededArchetypes() as $archetype) {
            $weights = $archetype->getTraitWeights();
            $formula = $weights['formula'] ?? [];

            $sum = array_sum(array_map('abs', array_values($formula)));
            $this->assertEqualsWithDelta(
                1.0,
                $sum,
                0.0001,
                sprintf('Archetype "%s" absolute weights sum to %s, expected 1.0.', $archetype->getSlug(), $sum),
            );

            $threshold = $weights['threshold'] ?? null;
            $this->assertIsInt($threshold, sprintf('"%s" has no integer threshold.', $archetype->getSlug()));
            $this->assertGreaterThanOrEqual(0, $threshold);
            $this->assertLessThanOrEqual(100, $threshold);
        }
    }

    public function testNoArchetypeUsesTheRetiredPhantomTraits(): void
    {
        foreach ($this->seededArchetypes() as $archetype) {
            $keys = array_keys($archetype->getTraitWeights()['formula'] ?? []);

            foreach (['bravery', 'ego', 'confidence'] as $phantom) {
                $this->assertNotContains(
                    $phantom,
                    $keys,
                    sprintf('Archetype "%s" reintroduced the phantom trait "%s".', $archetype->getSlug(), $phantom),
                );
            }
        }
    }
}

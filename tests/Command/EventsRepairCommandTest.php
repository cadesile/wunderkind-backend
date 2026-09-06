<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers app:events:repair — the clean-up for templates damaged by the old nested admin form,
 * plus the trait and severity normalisations.
 */
class EventsRepairCommandTest extends KernelTestCase
{
    private const PREFIX = 'repair-probe-';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();
        parent::tearDown();
    }

    private function repair(bool $apply = true): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:events:repair'));
        $tester->execute($apply ? ['--apply' => true] : []);
        $this->em->clear();

        return $tester->getDisplay();
    }

    private function persist(string $suffix, array $impacts, ?array $conditions = null, ?string $severity = null): void
    {
        $template = new GameEventTemplate(
            self::PREFIX . $suffix,
            EventCategory::NPC_INTERACTION,
            'Repair Probe',
            'Body.',
            $impacts,
        );
        $template->setFiringConditions($conditions);
        $template->setSeverity($severity);
        $this->em->persist($template);
        $this->em->flush();
    }

    private function reload(string $suffix): GameEventTemplate
    {
        return $this->em->getRepository(GameEventTemplate::class)
            ->findOneBy(['slug' => self::PREFIX . $suffix]);
    }

    /** The headline case: the exact shape the old EventImpactsType wrote. */
    public function testCorruptedNumericKeyImpactsAreRestored(): void
    {
        $this->persist('corrupted', [
            '0' => ['target' => 'player_1.morale', 'delta' => 3],
            '1' => ['target' => 'player_2.morale', 'delta' => -2],
            '2' => ['target' => 'pair.relationship', 'delta' => 5],
            'selection_logic' => [
                'target_type' => 'player',
                'count'       => null,
                'filter'      => ['position' => null, 'active_only' => false, 'min_age' => null],
            ],
            'stat_changes'    => [['target' => null, 'field' => null, 'operator' => 'add', 'value' => null]],
            'duration_config' => ['ticks' => null, 'completion_event_slug' => null],
            'choices'         => [],
        ]);

        $this->repair();
        $impacts = $this->reload('corrupted')->getImpacts();

        self::assertSame([
            ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 3],
            ['target' => 'player_2', 'field' => 'morale', 'operator' => 'subtract', 'value' => 2],
            ['target' => 'pair', 'field' => 'relationship', 'operator' => 'add', 'value' => 5],
        ], $impacts['stat_changes']);

        // Empty form artefacts are gone.
        self::assertArrayNotHasKey('selection_logic', $impacts);
        self::assertArrayNotHasKey('duration_config', $impacts);
        self::assertArrayNotHasKey('choices', $impacts);
        self::assertArrayNotHasKey('0', $impacts);
    }

    /** A sub-structure carrying real values is content, not an artefact. */
    public function testMeaningfulSubStructuresAreKept(): void
    {
        $this->persist('keeps', [
            '0' => ['target' => 'player_1.morale', 'delta' => 3],
            'selection_logic' => [
                'target_type' => 'player',
                'count'       => 2,
                'filter'      => ['position' => null, 'active_only' => false],
            ],
            'relationships' => [[
                'type' => 'friendship', 'player_1_ref' => 'player_1',
                'player_2_ref' => 'player_2', 'intensity' => 10,
            ]],
        ]);

        $this->repair();
        $impacts = $this->reload('keeps')->getImpacts();

        self::assertSame(2, $impacts['selection_logic']['count']);
        self::assertSame('friendship', $impacts['relationships'][0]['type']);
        self::assertCount(1, $impacts['stat_changes']);
    }

    /**
     * club.reputation has no canonical stat_changes equivalent, so converting would drop the
     * effect. The template is left alone and reported instead.
     */
    public function testUnconvertibleTargetIsReportedNotDropped(): void
    {
        $original = [
            '0' => ['target' => 'player_1.morale', 'delta' => 3],
            '1' => ['target' => 'club.reputation', 'delta' => -5],
            'choices' => [],
        ];
        $this->persist('unconvertible', $original);

        $output = $this->repair();

        self::assertSame($original, $this->reload('unconvertible')->getImpacts());
        self::assertStringContainsString('club.reputation', $output);
        self::assertStringContainsString('by hand', $output);
    }

    /**
     * The client's entity map only ever holds player_1, player_2, … — a bare `player.` prefix
     * resolves to nothing and the impact is dropped in silence.
     */
    public function testUnslottedPlayerTargetsAreSlotted(): void
    {
        $this->persist('unslotted', [
            ['target' => 'player.morale', 'delta' => -5],
            ['target' => 'player.personality.loyalty', 'delta' => -3],
            ['target' => 'player_2.morale', 'delta' => 1],
            ['target' => 'squad.morale', 'delta' => 2],
        ]);

        $this->repair();
        $impacts = $this->reload('unslotted')->getImpacts();

        self::assertSame('player_1.morale', $impacts[0]['target']);
        self::assertSame('player_1.personality.loyalty', $impacts[1]['target']);
        // Already-slotted and club-wide targets are untouched.
        self::assertSame('player_2.morale', $impacts[2]['target']);
        self::assertSame('squad.morale', $impacts[3]['target']);
    }

    public function testPhantomTraitsAreRemappedInFlatImpacts(): void
    {
        $this->persist('traits', [
            ['target' => 'player_1.personality.teamwork', 'delta' => 2],
            ['target' => 'player_2.personality.ego', 'delta' => -1],
            ['target' => 'player_1.personality.loyalty', 'delta' => 1],
        ]);

        $this->repair();
        $impacts = $this->reload('traits')->getImpacts();

        self::assertSame('player_1.personality.adaptability', $impacts[0]['target']);
        self::assertSame('player_2.personality.ambition', $impacts[1]['target']);
        // A real trait is untouched.
        self::assertSame('player_1.personality.loyalty', $impacts[2]['target']);
        // Deltas are already on the 1-20 scale and must not be rescaled.
        self::assertSame(2, $impacts[0]['delta']);
    }

    /** Trait requirement thresholds are 0-100 in old data; the client matrix is 1-20. */
    public function testTraitRequirementsAreRemappedAndRescaled(): void
    {
        $this->persist('conditions', [], [
            'minSquadMorale'         => 40,
            'actorTraitRequirements' => [
                ['trait' => 'ego', 'min' => 55],
                ['trait' => 'confidence', 'max' => 40],
                ['trait' => 'loyalty', 'min' => 12],
            ],
        ]);

        $this->repair();
        $conditions = $this->reload('conditions')->getFiringConditions();

        self::assertSame('ambition', $conditions['actorTraitRequirements'][0]['trait']);
        self::assertSame(11, $conditions['actorTraitRequirements'][0]['min'], '55 of 100 lands mid-scale');
        self::assertSame('pressure', $conditions['actorTraitRequirements'][1]['trait']);
        self::assertSame(9, $conditions['actorTraitRequirements'][1]['max']);

        // A real trait keeps its already-correct 1-20 threshold.
        self::assertSame(['trait' => 'loyalty', 'min' => 12], $conditions['actorTraitRequirements'][2]);
        self::assertSame(40, $conditions['minSquadMorale'], 'Non-trait keys must not be rescaled.');
    }

    /**
     * The old 0-100 scale was used with real trait names too, so rescaling has to key on the
     * value rather than on whether the trait needed renaming.
     */
    public function testRealTraitsOnTheOldScaleAreRescaled(): void
    {
        $this->persist('real-trait-scale', [], [
            'actorTraitRequirements' => [
                ['trait' => 'loyalty', 'min' => 55],
                ['trait' => 'determination', 'min' => 14],
            ],
        ]);

        $this->repair();
        $requirements = $this->reload('real-trait-scale')->getFiringConditions()['actorTraitRequirements'];

        self::assertSame(['trait' => 'loyalty', 'min' => 11], $requirements[0]);
        // Already within 1-20 — left alone.
        self::assertSame(['trait' => 'determination', 'min' => 14], $requirements[1]);
    }

    public function testLegacySeverityIsMigrated(): void
    {
        $this->persist('sev-low', [], null, 'low');
        $this->persist('sev-medium', [], null, 'medium');
        $this->persist('sev-high', [], null, 'high');
        $this->persist('sev-major', [], null, 'major');

        $this->repair();

        self::assertSame('minor', $this->reload('sev-low')->getSeverity());
        self::assertSame('minor', $this->reload('sev-medium')->getSeverity());
        self::assertSame('major', $this->reload('sev-high')->getSeverity());
        self::assertSame('major', $this->reload('sev-major')->getSeverity());
    }

    public function testDryRunChangesNothing(): void
    {
        $original = ['0' => ['target' => 'player_1.morale', 'delta' => 3], 'choices' => []];
        $this->persist('dry', $original, null, 'high');

        $output = $this->repair(apply: false);

        self::assertStringContainsString('--apply', $output);
        self::assertSame($original, $this->reload('dry')->getImpacts());
        self::assertSame('high', $this->reload('dry')->getSeverity());
    }

    public function testRepairIsIdempotent(): void
    {
        $this->persist('idempotent', [
            '0' => ['target' => 'player_1.personality.ego', 'delta' => 2],
            'choices' => [],
        ], ['actorTraitRequirements' => [['trait' => 'teamwork', 'min' => 55]]], 'high');

        $this->repair();
        $first = $this->reload('idempotent');
        $snapshot = [$first->getImpacts(), $first->getFiringConditions(), $first->getSeverity()];

        $output = $this->repair();
        $second = $this->reload('idempotent');

        self::assertSame($snapshot, [$second->getImpacts(), $second->getFiringConditions(), $second->getSeverity()]);
        self::assertStringNotContainsString(self::PREFIX . 'idempotent', $output, 'Second run should find nothing to do.');
    }

    private function removeFixtures(): void
    {
        foreach ($this->em->getRepository(GameEventTemplate::class)->findAll() as $template) {
            if (str_starts_with($template->getSlug(), self::PREFIX)) {
                $this->em->remove($template);
            }
        }
        $this->em->flush();
    }
}

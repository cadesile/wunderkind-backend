<?php

namespace App\Tests\Command;

use App\Entity\GameEventTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SeedGameEventsCommandTest extends KernelTestCase
{
    private const DRESSING_ROOM_SLUGS = [
        'dressing-room-rogue-press-leak',
        'dressing-room-unsanctioned-night-out',
        'dressing-room-wonderkid-head-turn',
        'dressing-room-veteran-intervention',
    ];

    private function tester(): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:seed-game-events'));
    }

    /** @return GameEventTemplate[] */
    private function seedAndFetch(): array
    {
        $tester = $this->tester();
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(GameEventTemplate::class)->findAll();
    }

    public function testSeedsAllFourDressingRoomCohesionTemplates(): void
    {
        $templates = $this->seedAndFetch();
        $bySlug    = [];
        foreach ($templates as $t) {
            $bySlug[$t->getSlug()] = $t;
        }

        foreach (self::DRESSING_ROOM_SLUGS as $slug) {
            $this->assertArrayHasKey($slug, $bySlug, "Missing seeded template: {$slug}");
        }
    }

    public function testDressingRoomTemplatesAreMajorNpcInteractionWithTwoChoicesEach(): void
    {
        $templates = $this->seedAndFetch();
        $bySlug    = [];
        foreach ($templates as $t) {
            $bySlug[$t->getSlug()] = $t;
        }

        foreach (self::DRESSING_ROOM_SLUGS as $slug) {
            $template = $bySlug[$slug];

            $this->assertSame('NPC_INTERACTION', $template->getCategory()->value, "{$slug}: wrong category");
            $this->assertSame('major', $template->getSeverity(), "{$slug}: severity must be 'major' for choices to be interactive (see SimulationService.triggerNpcIncident isActionable)");

            $impacts = $template->getImpacts();
            $this->assertArrayHasKey('choices', $impacts, "{$slug}: impacts must have a choices key");
            $this->assertCount(2, $impacts['choices'], "{$slug}: expected exactly 2 choices");

            foreach ($impacts['choices'] as $choice) {
                $this->assertArrayHasKey('emoji', $choice);
                $this->assertArrayHasKey('label', $choice);
                $this->assertNotSame('', $choice['label']);
                $this->assertArrayHasKey('stat_changes', $choice);
                $this->assertNotEmpty($choice['stat_changes'], "{$slug}: a choice with no stat_changes has no gameplay effect");

                foreach ($choice['stat_changes'] as $change) {
                    $this->assertArrayHasKey('target', $change);
                    $this->assertArrayHasKey('field', $change);
                    $this->assertArrayHasKey('operator', $change);
                    $this->assertArrayHasKey('value', $change);
                    $this->assertContains($change['operator'], ['add', 'subtract', 'set'], "{$slug}: invalid StatOperator '{$change['operator']}'");
                    // 'pair' target always pairs with the relationship field — the
                    // frontend has no other pair-scoped field to resolve it against.
                    if ('pair' === $change['target']) {
                        $this->assertSame('relationship', $change['field'], "{$slug}: 'pair' target must use field 'relationship'");
                    }
                }

                $this->assertArrayHasKey('manager_shift', $choice);
                foreach (['temperament', 'discipline', 'ambition'] as $key) {
                    $this->assertArrayHasKey($key, $choice['manager_shift'], "{$slug}: manager_shift missing '{$key}'");
                }
            }
        }
    }

    public function testDressingRoomTemplatesHaveValidFiringConditions(): void
    {
        $templates = $this->seedAndFetch();
        $bySlug    = [];
        foreach ($templates as $t) {
            $bySlug[$t->getSlug()] = $t;
        }

        // The 8-trait personality matrix — matches PersonalityMatrix in the frontend's
        // src/types/player.ts. A firing condition referencing anything outside this list
        // would silently never fire (the frontend evaluator only knows these 8).
        $validTraits = [
            'determination', 'professionalism', 'ambition', 'loyalty',
            'adaptability', 'pressure', 'temperament', 'consistency',
        ];

        foreach (self::DRESSING_ROOM_SLUGS as $slug) {
            $fc = $bySlug[$slug]->getFiringConditions();
            $this->assertIsArray($fc, "{$slug}: must declare firingConditions or it fires unconditionally every week");

            foreach (['actorTraitRequirements', 'subjectTraitRequirements'] as $key) {
                if (!isset($fc[$key])) {
                    continue;
                }
                foreach ($fc[$key] as $req) {
                    $this->assertContains($req['trait'], $validTraits, "{$slug}: unknown trait '{$req['trait']}' in {$key}");
                }
            }
        }
    }

    public function testIsIdempotent(): void
    {
        $this->seedAndFetch();
        $countAfterFirstRun = \count($this->seedAndFetch());

        $tester = $this->tester();
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $countAfterSecondRun = \count($em->getRepository(GameEventTemplate::class)->findAll());

        $this->assertSame($countAfterFirstRun, $countAfterSecondRun, 'Re-running the seed command must not create duplicates');
    }
}

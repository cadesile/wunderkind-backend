<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\GameEventTemplate;
use App\Entity\PlayerArchetype;
use App\Enum\EventCategory;
use App\Repository\GameEventTemplateRepository;
use App\Repository\PlayerArchetypeRepository;
use App\Service\NarrativeImportExportService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * GameEventTemplate and PlayerArchetype are exported through hand-written field lists in
 * NarrativeImportExportService, unlike FacilityTemplate's self-verifying toArray(). These
 * tests reflectively check every ORM-mapped column reaches the export, so the next field to
 * go the way FacilityTemplate's baseConstructionWeeks once did (exported but never read back,
 * or not exported at all) fails here instead of silently shipping.
 *
 * TacticalAdvantage is deliberately not covered here — it's a 3-field matchup row with no
 * meaningful risk of a column going unnoticed, and (unlike the other two) has no unique
 * business key a test fixture could safely clean up without risking a real catalogue row.
 */
class NarrativeFieldCoverageTest extends KernelTestCase
{
    private const SLUG = 'field-coverage-probe';

    private NarrativeImportExportService $service;
    private GameEventTemplateRepository  $eventTemplateRepository;
    private PlayerArchetypeRepository     $archetypeRepository;
    private EntityManagerInterface        $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->service                 = $container->get(NarrativeImportExportService::class);
        $this->eventTemplateRepository = $container->get(GameEventTemplateRepository::class);
        $this->archetypeRepository     = $container->get(PlayerArchetypeRepository::class);
        $this->em                      = $container->get(EntityManagerInterface::class);

        $this->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();
        parent::tearDown();
    }

    public function testEveryMappedColumnIsExportedForEventTemplate(): void
    {
        $template = new GameEventTemplate(self::SLUG, EventCategory::PLAYER, 'Probe title', 'Probe body');
        $this->em->persist($template);
        $this->em->flush();

        $exported = $this->findRowBySlug($this->service->export()['eventTemplates'], self::SLUG);

        $this->assertEveryColumnIsExported(GameEventTemplate::class, $exported);
    }

    public function testEveryMappedColumnIsExportedForPlayerArchetype(): void
    {
        $archetype = new PlayerArchetype(self::SLUG, 'Field Coverage Probe Archetype', 'Probe description');
        $this->em->persist($archetype);
        $this->em->flush();

        $exported = $this->findRowBySlug($this->service->export()['playerArchetypes'], self::SLUG);

        $this->assertEveryColumnIsExported(PlayerArchetype::class, $exported);
    }

    /** @param class-string $entityClass */
    private function assertEveryColumnIsExported(string $entityClass, array $exported): void
    {
        foreach ((new \ReflectionClass($entityClass))->getProperties() as $property) {
            if ($property->getAttributes(ORM\Column::class) === []
                || $property->getAttributes(ORM\Id::class) !== []
                // Server-side bookkeeping, not part of the content round-trip.
                || in_array($property->getName(), ['createdAt', 'updatedAt'], true)) {
                continue;
            }

            self::assertArrayHasKey(
                $property->getName(),
                $exported,
                sprintf(
                    '%s::$%s is persisted but missing from NarrativeImportExportService\'s export, so it can never reach an import.',
                    $entityClass,
                    $property->getName(),
                ),
            );
        }
    }

    private function findRowBySlug(array $rows, string $slug): array
    {
        foreach ($rows as $row) {
            if (($row['slug'] ?? null) === $slug) {
                return $row;
            }
        }

        self::fail("Fixture with slug '{$slug}' was not found in the export.");
    }

    private function removeFixtures(): void
    {
        foreach ($this->eventTemplateRepository->findBy(['slug' => self::SLUG]) as $e) {
            $this->em->remove($e);
        }
        foreach ($this->archetypeRepository->findBy(['slug' => self::SLUG]) as $a) {
            $this->em->remove($a);
        }
        $this->em->flush();
    }
}

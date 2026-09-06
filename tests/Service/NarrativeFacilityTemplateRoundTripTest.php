<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\FacilityTemplate;
use App\Repository\FacilityTemplateRepository;
use App\Repository\GameEventTemplateRepository;
use App\Service\NarrativeImportExportService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Facility templates are exported through FacilityTemplate::toArray() but imported by a
 * hand-written setter list in NarrativeImportExportService — two lists, separately
 * maintained, which is how baseConstructionWeeks came to be exported and never read back.
 *
 * These tests compare the two lists rather than naming fields, so the next field to drift
 * fails here too.
 */
class NarrativeFacilityTemplateRoundTripTest extends KernelTestCase
{
    private NarrativeImportExportService $service;
    private FacilityTemplateRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service    = self::getContainer()->get(NarrativeImportExportService::class);
        $this->repository = self::getContainer()->get(FacilityTemplateRepository::class);
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);

        $this->removeFixture();
    }

    protected function tearDown(): void
    {
        $this->removeFixture();
        parent::tearDown();
    }

    /** Every persisted column has to reach the export, or it can never reach an import. */
    public function testEveryMappedColumnIsExportedByToArray(): void
    {
        $exported = (new FacilityTemplate())->toArray();

        foreach ((new \ReflectionClass(FacilityTemplate::class))->getProperties() as $property) {
            if ($property->getAttributes(ORM\Column::class) === []
                || $property->getAttributes(ORM\Id::class) !== []
                // Server-side bookkeeping, refreshed by touch() on every import.
                || $property->getName() === 'updatedAt') {
                continue;
            }

            self::assertArrayHasKey(
                $property->getName(),
                $exported,
                sprintf(
                    'FacilityTemplate::$%s is persisted but missing from toArray(), so the '
                    . 'narrative export cannot carry it.',
                    $property->getName(),
                ),
            );
        }
    }

    /**
     * The generic drift guard: whatever the exporter emits must come back unchanged, so an
     * exported-but-unimported field cannot pass unnoticed.
     */
    public function testExportedFieldsAllSurviveImport(): void
    {
        $this->em->persist($this->fixture());
        $this->em->flush();
        $this->em->clear();

        $exported = $this->repository->findOneBy(['slug' => self::SLUG])->toArray();

        // Wipe the row's values back to entity defaults, then re-import over the top.
        $reset = new FacilityTemplate();
        $reset->setSlug(self::SLUG);
        $stored = $this->repository->findOneBy(['slug' => self::SLUG]);
        foreach ($reset->toArray() as $key => $value) {
            if ($key !== 'slug') {
                $stored->{'set' . ucfirst($key)}($value);
            }
        }
        $this->em->flush();
        $this->em->clear();

        $result = $this->service->import([
            'version'           => 2,
            'facilityTemplates' => [$exported],
        ]);
        $this->em->clear();

        self::assertSame([], $result['errors']);
        self::assertSame(
            $exported,
            $this->repository->findOneBy(['slug' => self::SLUG])->toArray(),
            'A field the narrative export emits is not applied on import.',
        );
    }

    /** Regression: baseConstructionWeeks was exported but silently reset to 4 on import. */
    public function testBaseConstructionWeeksIsImported(): void
    {
        $result = $this->service->import([
            'version'           => 2,
            'facilityTemplates' => [$this->fixture()->toArray()],
        ]);
        $this->em->clear();

        self::assertSame([], $result['errors']);
        self::assertSame(
            11,
            $this->repository->findOneBy(['slug' => self::SLUG])->getBaseConstructionWeeks(),
        );
    }

    /** A row the importer rejects must not reach the flush as a half-built entity. */
    public function testRejectedRowIsNotPersisted(): void
    {
        $result = $this->service->import([
            'version'        => 2,
            'eventTemplates' => [[
                'slug'     => self::SLUG,
                'category' => 'not_a_real_category',
                'title'    => 'Rejected',
            ]],
        ]);
        $this->em->clear();

        self::assertCount(1, $result['errors']);
        self::assertNull(
            self::getContainer()->get(GameEventTemplateRepository::class)->findOneBy(['slug' => self::SLUG]),
            'A rejected row was persisted anyway.',
        );
    }

    private const SLUG = 'round-trip-probe-facility';

    /** A template whose every field differs from the entity defaults. */
    private function fixture(): FacilityTemplate
    {
        $template = new FacilityTemplate(self::SLUG, 'Round Trip Probe', 'A probe.', 'MEDICAL', 987_654);
        $template->setWeeklyUpkeepBase(4_321);
        $template->setMatchdayIncome(777);
        $template->setMatchdayIncomeMultiplier(1.75);
        $template->setReputationBonus(3.5);
        $template->setMaxLevel(7);
        $template->setBaseConstructionWeeks(11);
        $template->setDecayBase(4.25);
        $template->setGameplayEffects(['trainingSpeed' => 1.4]);
        $template->setSortOrder(42);
        $template->setIsActive(false);
        $template->setImagePath('probe.png');

        return $template;
    }

    private function removeFixture(): void
    {
        foreach ($this->repository->findBy(['slug' => self::SLUG]) as $existing) {
            $this->em->remove($existing);
        }
        $this->em->flush();
    }
}

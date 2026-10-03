<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Excursion;
use App\Repository\ExcursionRepository;
use App\Service\NarrativeImportExportService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Excursions are exported through Excursion::toArray() but imported by a hand-written setter
 * list in NarrativeImportExportService — same two-lists-separately-maintained shape as
 * FacilityTemplate, and the same class of risk (see NarrativeFacilityTemplateRoundTripTest).
 *
 * These tests compare the two lists rather than naming fields, so the next field to drift
 * fails here too.
 */
class NarrativeExcursionRoundTripTest extends KernelTestCase
{
    private NarrativeImportExportService $service;
    private ExcursionRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service    = self::getContainer()->get(NarrativeImportExportService::class);
        $this->repository = self::getContainer()->get(ExcursionRepository::class);
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
        $exported = (new Excursion())->toArray();

        foreach ((new \ReflectionClass(Excursion::class))->getProperties() as $property) {
            if ($property->getAttributes(ORM\Column::class) === []
                || $property->getAttributes(ORM\Id::class) !== []
                // Server-side bookkeeping, refreshed by touch() on every import.
                || in_array($property->getName(), ['createdAt', 'updatedAt'], true)) {
                continue;
            }

            self::assertArrayHasKey(
                $property->getName(),
                $exported,
                sprintf(
                    'Excursion::$%s is persisted but missing from toArray(), so the '
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
        $reset  = new Excursion();
        $stored = $this->repository->findOneBy(['slug' => self::SLUG]);
        foreach ($reset->toArray() as $key => $value) {
            if ($key !== 'slug') {
                $stored->{'set' . ucfirst($key)}($value);
            }
        }
        $this->em->flush();
        $this->em->clear();

        $result = $this->service->import([
            'version'    => 4,
            'excursions' => [$exported],
        ]);
        $this->em->clear();

        self::assertSame([], $result['errors']);
        self::assertSame(
            $exported,
            $this->repository->findOneBy(['slug' => self::SLUG])->toArray(),
            'A field the narrative export emits is not applied on import.',
        );
    }

    /** Regression guard: toArray() exports imagePath with its public path prefix; import must collapse it back to just the filename, not store the full path. */
    public function testImagePathSurvivesAsFilenameRegardlessOfExportedPathPrefix(): void
    {
        $fixture = $this->fixture();
        $fixture->setImagePath('probe.png');
        $exported = $fixture->toArray();
        self::assertSame('/uploads/excursions/probe.png', $exported['imagePath']);

        $result = $this->service->import([
            'version'    => 4,
            'excursions' => [$exported],
        ]);
        $this->em->clear();

        self::assertSame([], $result['errors']);
        self::assertSame(
            'probe.png',
            $this->repository->findOneBy(['slug' => self::SLUG])->getImagePath(),
            'imagePath should be stored as a bare filename, not the exported public path.',
        );
    }

    /** A row the importer rejects must not reach the flush as a half-built entity. */
    public function testRejectedRowIsNotPersisted(): void
    {
        $result = $this->service->import([
            'version'    => 4,
            'excursions' => [[
                // Missing slug — upsertExcursion() must throw rather than persist a blank row.
                'title' => 'Rejected',
            ]],
        ]);
        $this->em->clear();

        self::assertCount(1, $result['errors']);
        self::assertNull(
            $this->repository->findOneBy(['title' => 'Rejected']),
            'A rejected row was persisted anyway.',
        );
    }

    private const SLUG = 'round-trip-probe-excursion';

    /** An excursion whose every field differs from the entity defaults. */
    private function fixture(): Excursion
    {
        $excursion = new Excursion(self::SLUG, 'Round Trip Probe', 'A probe.');
        $excursion->setCostPerPersonPence(123_456);
        $excursion->setEffectValue(88);
        $excursion->setNegativeFrequency(7);
        $excursion->setTargetAudience(Excursion::AUDIENCE_PLAYERS);
        $excursion->setPostSeasonOnly(true);
        $excursion->setCooldownWeeks(9);
        $excursion->setActive(false);

        return $excursion;
    }

    private function removeFixture(): void
    {
        foreach ($this->repository->findBy(['slug' => self::SLUG]) as $existing) {
            $this->em->remove($existing);
        }
        $this->em->flush();
    }
}

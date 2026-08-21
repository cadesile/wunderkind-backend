<?php

namespace App\Tests\Service;

use App\Entity\PlayerArchetype;
use App\Enum\ArchetypePolarity;
use App\Service\NarrativeImportExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class NarrativeArchetypeRoundTripTest extends KernelTestCase
{
    private NarrativeImportExportService $service;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(NarrativeImportExportService::class);
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);

        $application = new Application(self::$kernel);
        (new CommandTester($application->find('app:seed-archetypes')))->execute([]);
        $this->em->clear();
    }

    public function testExportEmitsSlugAndPolarity(): void
    {
        $rows = $this->service->export()['playerArchetypes'];

        $this->assertCount(20, $rows);
        $this->assertSame(
            ['slug', 'name', 'description', 'polarity', 'traitWeights'],
            array_keys($rows[0]),
        );
        $this->assertContains($rows[0]['polarity'], ['positive', 'negative']);
    }

    public function testReimportingAnExportUpdatesRatherThanDuplicates(): void
    {
        $export = $this->service->export();

        $result = $this->service->import($export);
        $this->em->clear();

        $this->assertSame([], $result['errors']);
        $this->assertCount(20, $this->em->getRepository(PlayerArchetype::class)->findAll());
    }

    public function testImportMatchesOnSlugSoRenamingIsNotADuplicate(): void
    {
        $export = $this->service->export();

        // A display-name change must update the existing row, not create a 21st.
        foreach ($export['playerArchetypes'] as $i => $row) {
            if ($row['slug'] === 'mercenary') {
                $export['playerArchetypes'][$i]['name'] = 'Renamed Mercenary';
            }
        }

        $this->service->import($export);
        $this->em->clear();

        $repo = $this->em->getRepository(PlayerArchetype::class);
        $this->assertCount(20, $repo->findAll());
        $this->assertSame('Renamed Mercenary', $repo->findOneBy(['slug' => 'mercenary'])->getName());
    }

    public function testLegacyTraitMappingKeyIsStillAccepted(): void
    {
        $export = $this->service->export();
        foreach ($export['playerArchetypes'] as $i => $row) {
            $export['playerArchetypes'][$i]['traitMapping'] = $row['traitWeights'];
            unset($export['playerArchetypes'][$i]['traitWeights']);
        }

        $this->service->import($export);
        $this->em->clear();

        $mercenary = $this->em->getRepository(PlayerArchetype::class)->findOneBy(['slug' => 'mercenary']);
        $this->assertSame(
            ['ambition' => 0.5, 'loyalty' => -0.5],
            $mercenary->getTraitWeights()['formula'],
        );
    }

    public function testMissingSlugAndBadPolarityAreReportedAsErrors(): void
    {
        $result = $this->service->import([
            'version'          => 2,
            'playerArchetypes' => [
                ['name' => 'No Slug', 'polarity' => 'positive'],
                ['slug' => 'bad_polarity', 'name' => 'Bad', 'polarity' => 'neutral'],
            ],
        ]);

        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('Missing slug', $result['errors'][0]);
        $this->assertStringContainsString('bad_polarity', $result['errors'][1]);
        $this->assertStringContainsString('invalid polarity', $result['errors'][1]);
    }

    public function testPolarityIsPreservedThroughARoundTrip(): void
    {
        $this->service->import($this->service->export());
        $this->em->clear();

        $repo = $this->em->getRepository(PlayerArchetype::class);
        $this->assertSame(ArchetypePolarity::POSITIVE, $repo->findOneBy(['slug' => 'iron_will'])->getPolarity());
        $this->assertSame(ArchetypePolarity::NEGATIVE, $repo->findOneBy(['slug' => 'hothead'])->getPolarity());
    }
}

<?php

namespace App\Tests\Command;

use App\Entity\PlayerArchetype;
use App\Enum\ArchetypePolarity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SeedArchetypesCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:seed-archetypes'));
    }

    /** @return PlayerArchetype[] */
    private function seedAndFetch(): array
    {
        $tester = $this->tester();
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(PlayerArchetype::class)->findAll();
    }

    public function testSeedsTwentyArchetypesTenOfEachPolarity(): void
    {
        $archetypes = $this->seedAndFetch();

        $this->assertCount(20, $archetypes);

        $byPolarity = ['positive' => 0, 'negative' => 0];
        foreach ($archetypes as $a) {
            ++$byPolarity[$a->getPolarity()->value];
        }

        $this->assertSame(['positive' => 10, 'negative' => 10], $byPolarity);
    }

    public function testSlugsAndNamesAreUniqueAndPopulated(): void
    {
        $archetypes = $this->seedAndFetch();

        $slugs = array_map(fn (PlayerArchetype $a) => $a->getSlug(), $archetypes);
        $names = array_map(fn (PlayerArchetype $a) => $a->getName(), $archetypes);

        $this->assertSame($slugs, array_unique($slugs), 'Slugs must be unique.');
        $this->assertSame($names, array_unique($names), 'Names must be unique.');
        $this->assertNotContains('', $slugs);
        $this->assertNotContains('', $names);

        foreach ($archetypes as $a) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $a->getSlug(),
                sprintf('Slug "%s" must be lower snake_case.', $a->getSlug()),
            );
            $this->assertNotSame('', trim($a->getDescription()));
        }
    }

    public function testIsIdempotent(): void
    {
        $this->seedAndFetch();
        $second = $this->seedAndFetch();

        // The command truncates first, so a re-run must not accumulate duplicates.
        $this->assertCount(20, $second);
    }

    public function testCatalogueContainsTheCuratedSlugs(): void
    {
        $archetypes = $this->seedAndFetch();
        $bySlug     = [];
        foreach ($archetypes as $a) {
            $bySlug[$a->getSlug()] = $a->getPolarity();
        }

        $this->assertSame(ArchetypePolarity::POSITIVE, $bySlug['standard_bearer'] ?? null);
        $this->assertSame(ArchetypePolarity::POSITIVE, $bySlug['dressing_room_glue'] ?? null);
        $this->assertSame(ArchetypePolarity::NEGATIVE, $bySlug['mercenary'] ?? null);
        $this->assertSame(ArchetypePolarity::NEGATIVE, $bySlug['flat_track_bully'] ?? null);
    }
}

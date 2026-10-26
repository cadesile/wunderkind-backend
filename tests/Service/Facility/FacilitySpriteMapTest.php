<?php

declare(strict_types=1);

namespace App\Tests\Service\Facility;

use App\Entity\FacilityTemplate;
use App\Service\Facility\FacilitySpriteMap;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * FacilitySpriteMap is generated from the sprite drop, so this does not re-check the
 * mapping against a hand-written copy — that would be the second copy the generator exists
 * to avoid. What it checks is the join the generator cannot see: that the sprites and the
 * GAME agree on which facilities exist.
 *
 * A slug seeded as a FacilityTemplate but absent from the sprites is a facility the
 * Facilities panel would silently never draw. A sprite slug with no template is a sprite
 * drawing something the game does not sell. Both are drift, and both stay invisible until
 * someone notices a missing building.
 */
class FacilitySpriteMapTest extends KernelTestCase
{
    /** @return list<string> */
    private function seededSlugs(): array
    {
        self::bootKernel();

        $slugs = self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT f.slug FROM ' . FacilityTemplate::class . ' f')
            ->getSingleColumnResult();

        sort($slugs);

        return $slugs;
    }

    public function testEverySeededFacilityHasASprite(): void
    {
        $seeded = $this->seededSlugs();

        if ($seeded === []) {
            $this->markTestSkipped('No FacilityTemplate rows seeded in this database.');
        }

        $missing = array_values(array_diff($seeded, FacilitySpriteMap::knownSlugs()));

        $this->assertSame([], $missing, sprintf(
            'Seeded facilities with no sprite: %s. The Facilities panel cannot draw these — '
            . 'either the sprite drop needs them or they should not be seeded.',
            implode(', ', $missing),
        ));
    }

    public function testEverySpriteFacilityIsASeededFacility(): void
    {
        $seeded = $this->seededSlugs();

        if ($seeded === []) {
            $this->markTestSkipped('No FacilityTemplate rows seeded in this database.');
        }

        $unknown = array_values(array_diff(FacilitySpriteMap::knownSlugs(), $seeded));

        $this->assertSame([], $unknown, sprintf(
            'Sprite facilities with no FacilityTemplate: %s. The sprite draws something the '
            . 'game does not seed.',
            implode(', ', $unknown),
        ));
    }

    /** The four sites are the four the compositor bundles; a fifth would need wiring. */
    public function testTheMapCoversExactlyTheFourSites(): void
    {
        $sites = array_keys(FacilitySpriteMap::SITES);
        sort($sites);

        $this->assertSame(
            ['medical', 'scouting', 'stadium', 'training'],
            $sites,
            'sites changed — the compositor entry and anything building configs need updating',
        );
    }

    /** Sprite ids must be unique within a site: two slugs on one id would overwrite each other. */
    public function testSpriteIdsAreUniqueWithinASite(): void
    {
        foreach (FacilitySpriteMap::SITES as $site => $slugs) {
            $ids = array_values($slugs);

            $this->assertSame(
                array_values(array_unique($ids)),
                $ids,
                sprintf('%s maps two slugs onto the same sprite id', $site),
            );
        }
    }

    /** 22 facilities across four sites — the number the game actually seeds. */
    public function testTheMapIsNotEmptyOrPartial(): void
    {
        $this->assertCount(22, FacilitySpriteMap::knownSlugs());
        $this->assertCount(4, FacilitySpriteMap::SITES['training']);
        $this->assertCount(6, FacilitySpriteMap::SITES['medical']);
        $this->assertCount(5, FacilitySpriteMap::SITES['scouting']);
        $this->assertCount(7, FacilitySpriteMap::SITES['stadium']);
    }
}

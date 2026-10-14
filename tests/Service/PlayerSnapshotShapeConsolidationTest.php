<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Player;
use App\Service\PlayerBrowseSerializer;
use App\Service\PlayerSnapshotSerializer;
use App\Service\WorldPackSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Guards the consolidation of the two former player shapes onto PlayerSnapshotSerializer.
 *
 * The consolidation's whole promise is that it was a SUPERSET — no key either shape used to
 * emit was dropped or redefined — so this pins the previous key sets literally. Those two
 * arrays are historical records, not a description of the current shape: they must never be
 * edited to match new output. A key disappearing from one of them is a breaking change to a
 * shipped client, which is exactly what should fail here.
 */
class PlayerSnapshotShapeConsolidationTest extends TestCase
{
    /** Exactly what WorldPackSnapshotBuilder::buildPlayerSnapshot() emitted before consolidation. */
    private const LEGACY_WORLDPACK_KEYS = [
        'id', 'firstName', 'lastName', 'position', 'nationality', 'countryCode', 'dateOfBirth',
        'contractValue', 'potential', 'currentAbility', 'morale', 'recruitmentSource', 'isActive',
        'physical', 'pace', 'technical', 'vision', 'power', 'stamina', 'heart', 'personality',
        'appearance', 'agent',
    ];

    /** Exactly what PlayerBrowseSerializer emitted before consolidation. */
    private const LEGACY_BROWSE_KEYS = [
        'id', 'firstName', 'lastName', 'dateOfBirth', 'nationality', 'countryCode', 'position',
        'potential', 'currentAbility', 'contractValue', 'tier', 'recruitmentSource', 'pace',
        'technical', 'vision', 'power', 'stamina', 'heart', 'overall', 'physical', 'appearance',
        'agent', 'personality', 'guardians',
    ];

    public function testWorldPackSnapshotStillEmitsEveryLegacyKey(): void
    {
        $snapshot = (new WorldPackSnapshotBuilder())->buildPlayerSnapshot($this->player());

        foreach (self::LEGACY_WORLDPACK_KEYS as $key) {
            $this->assertArrayHasKey($key, $snapshot, "world-pack snapshot dropped '$key'");
        }
    }

    public function testBrowseSerializerStillEmitsEveryLegacyKey(): void
    {
        $snapshot = (new PlayerBrowseSerializer())->serialize($this->player());

        foreach (self::LEGACY_BROWSE_KEYS as $key) {
            $this->assertArrayHasKey($key, $snapshot, "browse serializer dropped '$key'");
        }
    }

    /**
     * The point of consolidating: both paths now produce the same keys, so the client needs
     * one upsert path rather than two. Guardians is the single deliberate exception.
     */
    public function testBothPathsAgreeOnEveryKeyExceptGuardians(): void
    {
        $player = $this->player();

        $worldPack = (new WorldPackSnapshotBuilder())->buildPlayerSnapshot($player);
        $browse    = (new PlayerBrowseSerializer())->serialize($player);

        $this->assertSame(
            ['guardians'],
            array_values(array_diff(array_keys($browse), array_keys($worldPack))),
            'browse should differ from the world-pack shape only by guardians',
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($worldPack), array_keys($browse))),
            'the world-pack shape should carry nothing browse lacks',
        );
    }

    /**
     * `tier` keeps its original currentAbility meaning. Redefining it onto potential would
     * silently change what every already-shipped client reads from /api/scout/search.
     */
    public function testTierIsBandedOnCurrentAbilityAndPotentialTierOnPotential(): void
    {
        $player = new Player('A', 'B');
        $player->setCurrentAbility(10);   // local band
        $player->setPotential(88);        // elite band

        $snapshot = (new PlayerSnapshotSerializer())->serialize($player);

        $this->assertSame('local', $snapshot['tier']);
        $this->assertSame('elite', $snapshot['potentialTier']);
    }

    /** Guardians cost a lazy-collection load per player, so world packs must not pay it. */
    public function testGuardiansAreOptInOnly(): void
    {
        $serializer = new PlayerSnapshotSerializer();

        $this->assertArrayNotHasKey('guardians', $serializer->serialize($this->player()));
        $this->assertArrayHasKey('guardians', $serializer->serialize($this->player(), withGuardians: true));
    }

    /** isYouth is absent rather than false, so no pre-existing response gains a key. */
    public function testIsYouthIsOmittedUnlessAskedFor(): void
    {
        $serializer = new PlayerSnapshotSerializer();

        $this->assertArrayNotHasKey('isYouth', $serializer->serialize($this->player()));
        $this->assertTrue($serializer->serialize($this->player(), isYouth: true)['isYouth']);
    }

    private function player(): Player
    {
        $player = new Player('Test', 'Player');
        $player->setNationality('English');
        $player->setCurrentAbility(30);
        $player->setPotential(60);

        return $player;
    }
}

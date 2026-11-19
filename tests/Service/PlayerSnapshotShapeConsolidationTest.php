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
 *
 * ── ⚠️ THE RULE DIRECTLY ABOVE WAS BROKEN ON PURPOSE, ONCE, AT v11 (2026-11-18) ──────────
 *
 * `contractValue` was removed from `LEGACY_WORLDPACK_KEYS`. That is exactly the edit the
 * paragraph above forbids, so it is recorded here rather than done quietly:
 *
 *  - **What changed.** World packs no longer carry money of any kind — see
 *    `WorldPackSnapshotBuilder`'s class docblock and `WorldPackCarriesNoMoneyTest`. A pack is
 *    a census (teams, players, staff, abilities, facilities); every monetary quantity is
 *    resolved on the device at init from live config. `contractValue` was a player's price.
 *
 *  - **Why it is not the breaking change this rule exists to stop.** The rule protects clients
 *    that READ a shipped shape. This is a deliberate, co-ordinated cut: the app stopped reading
 *    `contractValue` from a pack in the same release and prices every ingested player itself
 *    (`economy/wages.ts` against the published tier scale). There is no fallback window by
 *    design — a backend that had dropped the key while the app still read it would price every
 *    squad at zero, which is why neither side ships alone.
 *
 *  - **What is still protected, and it is the more important half.** `LEGACY_BROWSE_KEYS` is
 *    UNTOUCHED and `contractValue` is still in it. `PlayerSnapshotSerializer` still emits the
 *    price, because the browse/scout-search/youth surfaces serve shared-pool rows priced at
 *    serve time and a browse row without a price is not a browsable player. The key is stripped
 *    at the PACK boundary only, in `buildPlayerSnapshot()`.
 *
 * So: one key, one array, one release, with the reason written down. **This is not a precedent.**
 * The next key that "has to" come out of these arrays should be argued on its own terms, and the
 * default answer is still no.
 */
class PlayerSnapshotShapeConsolidationTest extends TestCase
{
    /**
     * Exactly what WorldPackSnapshotBuilder::buildPlayerSnapshot() emitted before consolidation,
     * MINUS `contractValue`, which v11 removed from every pack. See the class docblock — that
     * one deletion is the single deliberate exception to the never-edit rule.
     */
    private const LEGACY_WORLDPACK_KEYS = [
        'id', 'firstName', 'lastName', 'position', 'nationality', 'countryCode', 'dateOfBirth',
        'potential', 'currentAbility', 'morale', 'recruitmentSource', 'isActive',
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
     * one upsert path rather than two. Guardians was the single deliberate exception;
     * `contractValue` is the second, added at v11.
     *
     * The two divergences have opposite reasons and both are permanent:
     *   - `guardians` costs a lazy-collection load per player, so a pack must not pay it;
     *   - `contractValue` is MONEY, so a cached pack must not carry it — while a browse row
     *     priced at serve time must.
     *
     * The assertion is still exact rather than relaxed to "contains", because the thing worth
     * catching is a THIRD divergence appearing without a reason.
     */
    public function testBothPathsAgreeOnEveryKeyExceptGuardiansAndThePrice(): void
    {
        $player = $this->player();

        $worldPack = (new WorldPackSnapshotBuilder())->buildPlayerSnapshot($player);
        $browse    = (new PlayerBrowseSerializer())->serialize($player);

        $this->assertSame(
            ['contractValue', 'guardians'],
            array_values(array_diff(array_keys($browse), array_keys($worldPack))),
            'browse should differ from the world-pack shape only by the price and guardians',
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

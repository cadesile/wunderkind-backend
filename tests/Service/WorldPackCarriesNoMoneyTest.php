<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Agent;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\CitySize;
use App\Enum\StaffRole;
use App\Repository\GameConfigRepository;
use App\Service\WorldInitializationService;
use App\Service\WorldPackSnapshotBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * THE INVARIANT v11 BUYS: a world pack carries no money.
 *
 * ## Why this test exists at all
 *
 * A pack is a CACHED payload, gated by `WorldInitializationService::WORLD_PACK_VERSION`. So
 * every monetary figure baked into one turns re-authoring that figure into a cache
 * invalidation across every country. Three of the five version bumps before v11 were purely
 * money — v6 sponsor bands, v7 payout ladders, v8 staff pay — and the v10 turnover authoring
 * left 208 of 256 cached tier payloads stale to publish numbers the device could have
 * resolved itself.
 *
 * v11 took all of it out. Everything monetary is now a function of live config resolved on the
 * device at initialisation: wages from the published tier wage scale, balances from the
 * `npcStartingBalanceByTier` ladder, league payouts from the UNCACHED
 * `GET /api/initialize/leagues`, the sponsor pot rolled on device from the division's bands.
 *
 * ## What it is really guarding against
 *
 * Not a single deletion — those are easy to review. **Money creeping back one key at a time.**
 * Every one of the removed keys had a good local reason to exist, and the next person to want
 * a figure on the device will reach for the payload that already has the squad in it. This
 * test is the answer to that: a pack is a census, so if you need a number that moves, publish
 * it somewhere uncached or derive it on the device.
 *
 * ⚠️ **If this test fails, the new key is the bug — not the test.** Do not add an exemption to
 * make a pack carry a price again. The one legitimate reason to touch the lists below is a
 * false positive on a key that is genuinely not an amount (see `ALLOWED_DESPITE_SUFFIX`).
 */
class WorldPackCarriesNoMoneyTest extends KernelTestCase
{
    /**
     * Exactly the keys v11 removed, by name. Kept literal and separate from the pattern scan
     * below so a regression names itself in the failure message rather than being reported as
     * "something that looks like money".
     */
    private const REMOVED_MONEY_KEYS = [
        'tvDeal',            // league — now GET /api/initialize/leagues
        'sponsorPot',        // league — now rolled on device from the division's bands
        'prizeMoney',        // league — now GET /api/initialize/leagues (LeaguePayoutResolver)
        'leaguePositionPot', // league — ditto
        'startingBalance',   // club   — now the npcStartingBalanceByTier config ladder
        'valuePence',        // club   — land price; GET /api/locations quotes it live
        'contractValue',     // player — now priced on device from the tier wage scale
        'commissionRate',    // player — agent fee, read off live config when a fee is charged
        'weeklySalary',      // staff  — now a tier-aware rung in the app's staffWages.ts
    ];

    /**
     * A pack key ending in any of these is an amount, and amounts do not belong in a pack.
     *
     * The suffix scan is the half that catches a key nobody has thought of yet —
     * `maintenanceCostPence`, `transferBudget`, `ticketPrice` — which is the actual failure
     * mode this test is for. Matching is case-insensitive on the suffix.
     */
    private const MONEY_SUFFIXES = [
        'Pence', 'Balance', 'Salary', 'Wage', 'Money', 'Pot', 'Price', 'Fee', 'Cost', 'Budget',
        'Deal', 'Value', 'Rate',
    ];

    /**
     * Keys that end in a money-shaped suffix but are NOT amounts.
     *
     * `leaguePositionDecreasePercent` is the one that matters and it does not match a suffix at
     * all — it is listed because it is the key a reader will come here wondering about. It is a
     * RULE (the percentage each finishing position steps down by), not a quantity of money, and
     * the app has no other source for it at init; hard-zeroing it once paid season-1 position
     * prizes at the full pot for every position.
     */
    private const ALLOWED_DESPITE_SUFFIX = [
        'leaguePositionDecreasePercent',
    ];

    private WorldPackSnapshotBuilder $builder;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->builder = self::getContainer()->get(WorldPackSnapshotBuilder::class);
    }

    /**
     * The whole club payload, nested: club → land, players → agent, staff, scouts. One assert
     * over the real shape beats one per key, because it also covers keys added in future.
     */
    public function testAClubSnapshotCarriesNoMoneyAnywhereInIt(): void
    {
        $snapshot = $this->builder->buildClubSnapshot(
            $this->club(),
            [$this->player()],
            [$this->staff()],
            [$this->scout()],
        );

        $this->assertNoMoneyIn($snapshot, 'club');
    }

    /**
     * The league payload, which is the pack's top level: `POST /api/initialize/league/{tier}`
     * returns `['tier' => n, 'data' => buildLeagueSnapshot(...)]`.
     */
    public function testALeagueSnapshotCarriesNoMoneyAnywhereInIt(): void
    {
        $league = new League('EN', 8, 'Test League 8');
        $league->setTvDeal(500_000);   // set on the ENTITY, to prove the snapshot ignores it

        $snapshot = self::getContainer()->get(WorldInitializationService::class)->buildLeagueSnapshot(
            $league,
            [],
            [],
            self::getContainer()->get(GameConfigRepository::class)->getConfig(),
        );

        $this->assertNoMoneyIn($snapshot, 'league');
    }

    /**
     * The starter pack goes through the same `buildPlayerSnapshot()`, so the founder's squad is
     * unpriced exactly as an NPC squad is. Worth its own assert because the two used to differ:
     * the founder's squad was the one squad in the pyramid outside the economy.
     */
    public function testTheFoundersSquadIsAsUnpricedAsAnNpcSquad(): void
    {
        $this->assertNoMoneyIn($this->builder->buildPlayerSnapshot($this->player()), 'starter player');
    }

    /**
     * The other half of the player rule, stated positively: the AGENT survives, only his fee
     * goes. Who represents a player is census data; what he charges is not.
     */
    public function testThePlayerKeepsHisAgentButNotTheAgentsFee(): void
    {
        $snapshot = $this->builder->buildPlayerSnapshot($this->player());

        self::assertIsArray($snapshot['agent'], 'the agent block itself must survive');
        self::assertSame('Super Agent', $snapshot['agent']['name']);
        self::assertArrayNotHasKey('commissionRate', $snapshot['agent']);
    }

    /**
     * ⚠️ THE OTHER DIRECTION, AND IT IS HALF THE POINT. `contractValue` and `commissionRate`
     * are stripped at the PACK boundary, not removed from `PlayerSnapshotSerializer`, because
     * that class is also the browse/scout-search/youth shape — and those surfaces serve
     * shared-pool rows priced at SERVE time by `Economy\ServeTimePlayerPricer`. A browse row
     * with no price is not a browsable player.
     *
     * So this pins that the shared serializer still prices. Deleting the key there to "finish
     * the job" is the plausible wrong move, and this is what fails when someone makes it.
     */
    public function testTheSharedSerializerStillPricesForTheBrowseSurfaces(): void
    {
        $browse = (new \App\Service\PlayerBrowseSerializer())->serialize($this->player());

        self::assertArrayHasKey('contractValue', $browse, 'browse rows must still carry a price');
        self::assertArrayHasKey('commissionRate', $browse['agent'], 'and so must the agent fee');
    }

    /** Recursive, because the money that mattered most was two levels down inside a club. */
    private function assertNoMoneyIn(array $payload, string $path): void
    {
        foreach ($payload as $key => $value) {
            $here = is_string($key) ? "{$path}.{$key}" : "{$path}[{$key}]";

            if (is_string($key) && !in_array($key, self::ALLOWED_DESPITE_SUFFIX, true)) {
                self::assertNotContains(
                    $key,
                    self::REMOVED_MONEY_KEYS,
                    "{$here} is a money key v11 removed — see this test's docblock before re-adding it",
                );

                foreach (self::MONEY_SUFFIXES as $suffix) {
                    self::assertFalse(
                        str_ends_with(strtolower($key), strtolower($suffix)),
                        "{$here} looks like an amount ('{$suffix}'). A pack is a census, not a "
                        . 'price list: publish it on an uncached endpoint or derive it on device.',
                    );
                }
            }

            if (is_array($value)) {
                $this->assertNoMoneyIn($value, $here);
            }
        }
    }

    private function club(): NpcClub
    {
        return new NpcClub(
            name: 'Test United', country: 'EN', tier: 8, reputation: 20,
            primaryColor: '#111111', secondaryColor: '#eeeeee',
            // A BALANCE ON THE ENTITY, deliberately: the club has one, the snapshot must not.
            balance: 5_000_000,
            facilities: ['stadium' => 3], citySize: CitySize::MEDIUM,
        );
    }

    private function player(): Player
    {
        $player = new Player('Test', 'Player');
        $player->setNationality('English');
        $player->setCurrentAbility(30);
        $player->setPotential(60);
        // PRICED ON THE ENTITY, so the assert proves the strip and not an unset default.
        $player->setContractValue(12_345);

        $agent = new Agent('Super Agent');
        $agent->setCommissionRate('0.0750');
        $player->setAgent($agent);

        return $player;
    }

    private function staff(): Staff
    {
        $staff = new Staff('Test', 'Coach', StaffRole::COACH);
        $staff->setCoachingAbility(40);
        // As above: the backend goes on writing this for its non-pack consumers.
        $staff->setWeeklySalary(80_000);

        return $staff;
    }

    private function scout(): Scout
    {
        $scout = new Scout('Test Scout');
        $scout->setExperience(35);
        $scout->setWeeklySalary(4_000);

        return $scout;
    }
}

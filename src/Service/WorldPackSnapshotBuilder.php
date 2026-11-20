<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\NpcClub;
use App\Entity\Player;
use App\Entity\PoolConfig;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\Country;
use App\Enum\PlayerPosition;
use App\Enum\Tier;

/**
 * Pure entity→array snapshot mappers plus the position/agent-distribution
 * helpers they depend on. Extracted from WorldInitializationService so a
 * per-club generation service can build snapshots without creating a
 * circular dependency (WorldInitializationService → per-club generator →
 * WorldInitializationService). Stateless — no pool-table or DB dependency;
 * every method here works identically on a freshly-built, never-persisted
 * entity as it does on one pulled from a pool table.
 *
 * ── A PACK IS A CENSUS, NOT A PRICE LIST (owner, 2026-11-18) ─────────────────────────────
 *
 * **No snapshot this class builds carries money.** Identities, squads, abilities, facilities,
 * personalities and the ground a club stands on — all of that is what a pack is for, and all
 * of it is stable. Every monetary quantity is a function of live, admin-editable config, so
 * it is resolved on the DEVICE at initialisation instead: wages through the app's
 * `economy/wages.ts` against the published tier scale, balances through its
 * `npcStartingBalanceByTier` ladder, league payouts through the uncached
 * `GET /api/initialize/leagues`.
 *
 * WHY, measured: a pack is a cached payload gated by `WorldInitializationService
 * ::WORLD_PACK_VERSION`, so every figure baked into one makes authoring that figure a cache
 * invalidation. Of the five version bumps before this change, THREE were purely money (v6
 * sponsor bands, v7 payout ladders, v8 staff pay), and the v10 bump for a turnover table left
 * 208 of 256 cached tier payloads stale. None of those would have happened under this rule.
 *
 * `WorldPackCarriesNoMoneyTest` pins it. Adding a money key back here is what it exists to
 * fail, so if it fails, the key is the bug — not the test.
 */
class WorldPackSnapshotBuilder
{
    /**
     * Keys `PlayerSnapshotSerializer` emits for its browse/youth consumers that a PACK must
     * not carry. See `buildPlayerSnapshot()` for why the strip happens here and not there.
     */
    private const MONEY_KEYS_STRIPPED_FROM_PACKS = ['contractValue'];

    /** As above, one level down, inside the nested `agent` block. */
    private const MONEY_KEYS_STRIPPED_FROM_AGENTS = ['commissionRate'];

    public function __construct(
        private readonly PlayerSnapshotSerializer $playerSnapshots = new PlayerSnapshotSerializer(),
    ) {}

    /**
     * Distributes $total players across GK/DEF/MID/ATT using PoolConfig position weights.
     * Uses the largest-remainder method so the counts always sum exactly to $total.
     *
     * @return array<string, int>  Keys are PlayerPosition backed-enum values ('GK','DEF','MID','ATT')
     */
    public function distributeByPosition(int $total, PoolConfig $config): array
    {
        $weights = [
            PlayerPosition::GOALKEEPER->value => $config->getPositionWeightGk(),
            PlayerPosition::DEFENDER->value   => $config->getPositionWeightDef(),
            PlayerPosition::MIDFIELDER->value => $config->getPositionWeightMid(),
            PlayerPosition::ATTACKER->value   => $config->getPositionWeightAtt(),
        ];

        $totalWeight = array_sum($weights);
        $counts      = [];
        $fractions   = [];
        $assigned    = 0;

        foreach ($weights as $pos => $weight) {
            $exact        = $total * $weight / $totalWeight;
            $counts[$pos] = (int) floor($exact);
            $fractions[$pos] = $exact - $counts[$pos];
            $assigned    += $counts[$pos];
        }

        // Distribute remainder to positions with the largest fractional parts
        $remainder = $total - $assigned;
        arsort($fractions);
        foreach (array_keys($fractions) as $pos) {
            if ($remainder <= 0) break;
            $counts[$pos]++;
            $remainder--;
        }

        return $counts;
    }

    /**
     * Reassigns each player a random agent from the available agent pool. Multiple
     * players may reference the same agent — a many-to-one relationship is the
     * intended shape (one agent represents several players). No-op when the pool is
     * empty. Called at world-pack generation time, before players are snapshotted
     * and deleted, so the in-memory FK is read straight into the snapshot with no flush.
     *
     * @param Player[] $players
     * @param Agent[]  $agents
     */
    public function assignAgents(array $players, array $agents): void
    {
        if ($agents === []) {
            return;
        }
        foreach ($players as $player) {
            $player->setAgent($agents[array_rand($agents)]);
        }
    }

    public function buildClubSnapshot(NpcClub $club, array $players, array $staff, array $scouts = []): array
    {
        return [
            'id'              => (string) $club->getId(),
            'name'            => $club->getName(),
            'abbreviation'    => $club->getAbbreviation() ?? ClubInitializationService::generateAbbreviation($club->getName()),
            'tier'            => $club->getTier(),
            'reputation'      => $club->getReputation(),
            'primaryColor'   => $club->getPrimaryColor(),
            'secondaryColor' => $club->getSecondaryColor(),
            'identity'       => $club->getIdentity(),
            'stadiumName'    => $club->getStadiumName(),
            'facilities'     => $club->getFacilities(),
            'region'         => $club->getRegion(),
            'citySize'       => $club->getCitySize()->value,
            'populationSize' => $club->getPopulationSize(),
            'isCapital'      => $club->isCapital(),
            'land'           => $this->buildLandSnapshot($club),
            'personality'    => [
                'playingStyle'       => $club->getPlayingStyle(),
                'financialApproach'  => $club->getFinancialApproach(),
                'managerTemperament' => $club->getManagerTemperament(),
            ],
            'players' => array_map(fn(Player $p) => $this->buildPlayerSnapshot($p), $players),
            'staff'   => array_map(fn(Staff $s) => $this->buildStaffSnapshot($s), $staff),
            'scouts'  => array_map(fn(Scout $s) => $this->buildScoutSnapshot($s), $scouts),
        ];
    }

    /**
     * The ground this club stands on: the town, and the plot inside it.
     *
     * ADDITIVE AND NULLABLE. `region`, `citySize`, `populationSize` and `isCapital` stay
     * exactly where they are on the club — nothing a client reads today moves — and this block
     * is a second, fuller view of the same town plus the plot facts that had no home before.
     * The duplication is deliberate: a client that wants the land can read one object, and one
     * that does not keeps working untouched.
     *
     * NULL WHEN THERE IS NO TOWN, which is a real state and not an error. A country generated
     * before its locations were seeded draws from LocationCatalogueService::PLACEHOLDER_PLACES,
     * whose "towns" are "Capital" and "Northern" and will never be location rows — see
     * NpcClub::$location.
     *
     * `plotNumber` IS NULL FOR MOST CLUBS, and that is the honest answer rather than a gap.
     * Only the first LocationCapacityService::NPC_PLOT_CAP clubs in a town are seated on a
     * plot; a town's third club occupies no ground, so there is no number to give. A club in a
     * town that has never been priced has no plots to sit on either, and gets one when
     * app:npc-clubs:seat-plots runs after enrichment.
     *
     * ── NO `valuePence`, SINCE 2026-11-18 ────────────────────────────────────────────────
     *
     * This block used to quote the plot's price, and it was the last money left on a club.
     * A pack is a CENSUS now (see the class docblock): it says which ground this club stands
     * on, not what the ground is worth. The price is a live figure — `LandPlot
     * ::getEffectiveLandPence()` falls back to the town's value, and both are operator-authored
     * and admin-editable — so baking it into a cached payload meant re-authoring one town
     * invalidated every pack in the country. `GET /api/locations` quotes the catalogue price
     * live, which is where a client that wants to BUY ground already reads it.
     *
     * @return array{name: string, region: string, plotNumber: ?int, capital: bool}|null
     */
    public function buildLandSnapshot(NpcClub $club): ?array
    {
        $location = $club->getLocation();

        if ($location === null) {
            return null;
        }

        $plot = $club->getLandPlot();

        return [
            'name'       => $location->getTown(),
            'region'     => $location->getRegion(),
            'plotNumber' => $plot?->getPlotNumber(),
            'capital'    => $location->isCapital(),
        ];
    }

    /**
     * Delegates to PlayerSnapshotSerializer, which owns the single canonical player shape
     * this method's own output became the model for — see that class. Guardians are
     * deliberately left out here: Player::$guardians is lazy, so including them would fire
     * a query per player across a whole country's pack.
     *
     * Emits three keys this method did not before — `tier`, `overall` and `potentialTier`;
     * nothing it used to emit was removed or changed.
     *
     * ── THE MONEY IS STRIPPED HERE, AND HERE IS THE ONLY PLACE IT CAN BE ──────────────────
     *
     * `contractValue` and `agent.commissionRate` are still emitted by
     * `PlayerSnapshotSerializer` because its OTHER consumers need them: `PlayerBrowseSerializer`
     * (GET /api/scout/search, GET /api/players/foreign) and GET /api/players/youth serve
     * shared-pool rows priced at SERVE time by `Economy\ServeTimePlayerPricer`, and a browse
     * row with no price is not a browsable player. So the figure cannot be removed from the
     * serializer, and it must not be RE-priced there either — `WorldPackSnapshotBuilder`
     * delegates to it, so pricing inside it would change cached pack content on every read
     * with no version bump (architecture-notes.md's trap 4).
     *
     * That leaves exactly one correct seam: the pack boundary, which is this method. The keys
     * are unset on the way out, so the canonical shape stays intact for everyone else and a
     * pack carries no price.
     */
    public function buildPlayerSnapshot(Player $player): array
    {
        $snapshot = $this->playerSnapshots->serialize($player);

        foreach (self::MONEY_KEYS_STRIPPED_FROM_PACKS as $key) {
            unset($snapshot[$key]);
        }

        // The agent survives — WHO represents this player is census data. WHAT he charges is
        // not: `commissionRate` is read off live config at the moment a fee is calculated.
        if (isset($snapshot['agent']) && is_array($snapshot['agent'])) {
            foreach (self::MONEY_KEYS_STRIPPED_FROM_AGENTS as $key) {
                unset($snapshot['agent'][$key]);
            }
        }

        return $snapshot;
    }

    public function buildStaffSnapshot(Staff $staff): array
    {
        // getRole() returns StaffRole backed enum — use ->value
        return [
            'id'              => (string) $staff->getId(),
            'firstName'       => $staff->getFirstName(),
            'lastName'        => $staff->getLastName(),
            'dateOfBirth'     => $staff->getDob()?->format('Y-m-d'),
            'nationality'     => $staff->getNationality() ?? '',
            'countryCode'     => $staff->getNationality() !== null ? Country::fromNationality($staff->getNationality())?->value : null,
            'role'            => $staff->getRole()->value,
            'tier'            => Tier::fromScore($staff->getCoachingAbility())->value,
            'coachingAbility' => $staff->getCoachingAbility(),
            'scoutingRange'   => $staff->getScoutingRange(),
            'morale'          => $staff->getMorale(),
            'specialisms'     => $staff->getSpecialisms() ?? [],
            'appearance'      => $staff->getAppearance(),
            'personality'     => $staff->getPersonality()->toArray(),
        ];
    }

    /**
     * NO `weeklySalary` HERE, DELIBERATELY. Scout gained a wage column on 2026-11-05 and
     * GET /api/market/data exposes it, but this is the CACHED world-pack shape: adding a key
     * is a payload shape change, and WorldInitializationService::WORLD_PACK_VERSION's docblock
     * requires a bump for one — which invalidates every country's pack. Nothing needs the
     * figure here yet (the funding projection reads the live market, not a pack), so the shape
     * stays put. Same resolution the NPC-club `locationId` work reached.
     */
    public function buildScoutSnapshot(Scout $scout): array
    {
        return [
            'id'          => (string) $scout->getId(),
            'name'        => $scout->getName(),
            'dateOfBirth' => $scout->getDob()?->format('Y-m-d'),
            'nationality' => $scout->getNationality() ?? '',
            'countryCode' => $scout->getNationality() !== null ? Country::fromNationality($scout->getNationality())?->value : null,
            'experience'  => $scout->getExperience(),
            // ADDITIVE, 2026-11-20, AND THIS KEY IS WHY WORLD_PACK_VERSION WENT 11 → 12.
            //
            // It is census, not money, so the pack-is-a-census rule permits it: `ability` is what
            // a scout IS, exactly like the abilities this snapshot already carries for players and
            // staff, and v9 and v10 were both ability-content bumps of the same kind. The
            // `weeklySalary` omission documented below is NOT a precedent against it — a salary is
            // money and resolves on the device; an ability cannot be resolved from config because
            // it is per-row data. Derived and stored on the backend rather than computed on device
            // precisely so there is one source for it.
            'ability'     => $scout->getAbility(),
            // STILL DERIVED FROM `experience`, DELIBERATELY — re-pointing this key at `ability`
            // would be a changed value under an unchanged key, i.e. a break. See MarketDataService.
            'tier'        => Tier::fromScore($scout->getExperience())->value,
            'judgements'  => $scout->getJudgements(),
            'appearance'  => $scout->getAppearance(),
            'personality' => $scout->getPersonality()->toArray(),
        ];
    }
}

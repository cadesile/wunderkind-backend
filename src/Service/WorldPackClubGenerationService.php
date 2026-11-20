<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\NpcClub;
use App\Entity\Player;
use App\Entity\PoolConfig;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;

/**
 * Generates every entity one NPC club needs for a world-pack tier build — fresh,
 * in-memory, never persisted — directly from tier config, with no pool-table
 * dependency. The single per-club entry point shared by
 * WorldInitializationService::buildTierPack()'s synchronous loop (used both for
 * admin-triggered warming and InitializeController's on-demand lazy-fill path)
 * and the async per-club Messenger handler.
 */
class WorldPackClubGenerationService
{
    public function __construct(
        private readonly PlayerGenerationService $playerGen,
        private readonly StaffGenerationService $staffGen,
        private readonly ScoutGenerationService $scoutGen,
        private readonly WorldPackSnapshotBuilder $snapshotBuilder,
        private readonly NameGeneratorService $nameGenerator,
    ) {}

    /**
     * @param array<string, mixed> $tierConf StarterConfig::getNpcSquadConfig()[tierKey] shape
     * @param array{min: int, max: int} $abilityRange
     * @param Agent[] $agents bounded agent pool for this tier (same subset for every
     *                        club in the tier, so an agent can represent multiple clubs)
     * @return Player[]
     */
    /**
     * ⚠️ NO `$country`, `$tier` OR `$clubReputation` SINCE v11. All three were here for one
     * reason — pricing the squad at generation — and nothing prices at generation any more.
     * They are not "available if needed": accepting them is what would let a price quietly
     * return to a cached payload. The device prices from the tier it is initialising into.
     * `generateStaff()` below still takes country and tier, because the backend goes on
     * writing `staff.weekly_salary` for its NON-pack consumers.
     */
    public function generatePlayers(
        array $tierConf,
        array $abilityRange,
        string $nationality,
        PoolConfig $poolConfig,
        array $agents,
    ): array {
        $totalPlayers = random_int((int) $tierConf['playerMin'], (int) $tierConf['playerMax']);
        $foreignPct   = (int) $tierConf['foreignPercent'];
        $posCounts    = $this->snapshotBuilder->distributeByPosition($totalPlayers, $poolConfig);

        // An NPC side is a FIRST TEAM, so it generates inside the first-team age band
        // rather than PoolConfig's pool-wide range. That range has to span youth ages for
        // the pool to supply GET /api/players/youth, and without this every NPC club in
        // the pyramid was generated as children — the same bug as the starter pack, just
        // on the generation path rather than the draw.
        $ageRange = $poolConfig->firstTeamAgeRange();

        $players = [];
        foreach ($posCounts as $posValue => $posTotal) {
            $position      = PlayerPosition::from($posValue);
            $foreignCount  = (int) round($posTotal * $foreignPct / 100);
            $domesticCount = $posTotal - $foreignCount;

            for ($i = 0; $i < $domesticCount; $i++) {
                $players[] = $this->buildPlayer($position, $nationality, $abilityRange, $ageRange);
            }
            for ($i = 0; $i < $foreignCount; $i++) {
                $players[] = $this->buildPlayer($position, $this->foreignNationality($nationality), $abilityRange, $ageRange);
            }
        }

        $this->snapshotBuilder->assignAgents($players, $agents);

        return $players;
    }

    /**
     * Manager/Coach/Chairman are unfiltered by nationality (long-standing NPC-club
     * precedent); DOF/Facility Manager use the club's own nationality, matching
     * StarterPackService's pattern for the player's own club.
     *
     * ⚠️ **NO `$abilityRange` SINCE A25, 2026-11-20 (owner ruling).** This method used to take
     * the club's PLAYER band and hand it to every staff member — the same array the squad was
     * generated from, which is a POTENTIAL figure against a staff ability that has no
     * current/potential split. `$country` and `$tier` are now the whole of what staff banding
     * needs: `StaffGenerationService` resolves the authored per-country/per-tier staff table
     * from them. The parameter is REMOVED rather than ignored, so a caller cannot put the
     * player band back without changing this signature. See StaffAbilityRangeService.
     *
     * @param array<string, mixed> $tierConf
     * @return Staff[]
     */
    public function generateStaff(
        array $tierConf,
        string $nationality,
        string $country = '',
        int $tier = 0,
    ): array {
        $staff = [];

        for ($i = 0; $i < (int) $tierConf['managerCount']; $i++) {
            $staff[] = $this->staffGen->build(StaffRole::MANAGER, null, $country, $tier);
        }
        for ($i = 0; $i < (int) $tierConf['coachCount']; $i++) {
            $staff[] = $this->staffGen->build(StaffRole::COACH, null, $country, $tier);
        }
        for ($i = 0; $i < (int) $tierConf['chairmanCount']; $i++) {
            $staff[] = $this->staffGen->build(StaffRole::CHAIRMAN, null, $country, $tier);
        }
        for ($i = 0; $i < (int) ($tierConf['directorOfFootballCount'] ?? 0); $i++) {
            $staff[] = $this->staffGen->build(StaffRole::DIRECTOR_OF_FOOTBALL, $nationality, $country, $tier);
        }
        for ($i = 0; $i < (int) ($tierConf['facilityManagerCount'] ?? 0); $i++) {
            $staff[] = $this->staffGen->build(StaffRole::FACILITY_MANAGER, $nationality, $country, $tier);
        }

        return $staff;
    }

    /**
     * `$country` and `$tier` are the club's own, and they band its scouts off the SAME
     * authored staff table as its coaches — see generateStaff() above for why the
     * `$abilityRange` parameter both methods used to take is gone (A25, 2026-11-20).
     *
     * Scouts were missed when staff ability was first banded, because a Scout is its own
     * entity and never goes through `staffWeeklyPence()`. The result was a rating-33 scout in
     * a tier-8 squad of rating-2-7 coaches, which the app's proportional wage split then paid
     * £2,230/wk. The banded figure lands on `experience`; `PoolConfig::$scoutExperienceMin/Max`
     * remains the untiered window and was left alone by ruling.
     *
     * @param array<string, mixed> $tierConf
     * @return Scout[]
     */
    public function generateScouts(array $tierConf, string $nationality, string $country = '', int $tier = 0): array
    {
        $scouts = [];
        for ($i = 0; $i < (int) ($tierConf['scoutCount'] ?? 0); $i++) {
            $scouts[] = $this->scoutGen->build($nationality, $country, $tier);
        }
        return $scouts;
    }

    /**
     * @param Player[] $players
     * @param Staff[]  $staff
     * @param Scout[]  $scouts
     */
    public function buildSnapshot(NpcClub $club, array $players, array $staff, array $scouts): array
    {
        return $this->snapshotBuilder->buildClubSnapshot($club, $players, $staff, $scouts);
    }

    /**
     * ── NOTHING IS PRICED HERE ANY MORE (v11, 2026-11-18) ────────────────────────────────
     *
     * This used to set a contract value through `WageModelResolver::playerWeeklyPence()`, with
     * the country, tier and club reputation that made a world-pack club "the priced
     * population". It does not, because the pack no longer carries `contractValue` at all —
     * `WorldPackSnapshotBuilder::buildPlayerSnapshot()` strips it, and the device prices every
     * ingested player from the published tier scale at initialisation.
     *
     * So `PlayerGenerationService::generate()` leaves `contractValue` at its 0 default and
     * that 0 is now CORRECT and unread: it is never serialised into a pack, and nothing
     * downstream of generation looks at it. Do not "fix" it by pricing here — a price computed
     * at generation is a price frozen into a cached payload, which is the entire defect v11
     * removes. Re-authoring a turnover figure must reach a new save with no version bump.
     *
     * The shared pool is the other half of the old two-sided rule and is unchanged:
     * `MarketPoolService` still stores a legacy figure and `Economy\ServeTimePlayerPricer`
     * still prices it at serve time. Only the generated-and-stored side has gone away.
     */
    /** @param array{min: int, max: int} $abilityRange */
    /**
     * @param array{min: int, max: int}      $abilityRange
     * @param array{min: int, max: int}|null $ageRange First-team band; null keeps PoolConfig's
     *                                       pool-wide range (callers that are not squads).
     */
    private function buildPlayer(
        PlayerPosition $position,
        string $nationality,
        array $abilityRange,
        ?array $ageRange = null,
    ): Player {
        return $this->playerGen->generate($position, RecruitmentSource::YOUTH_INTAKE, $nationality, $abilityRange, $ageRange);
    }

    /** Picks a random nationality different from $exclude where possible (bounded retries). */
    private function foreignNationality(string $exclude): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = $this->nameGenerator->getRandomNationality();
            if ($candidate !== $exclude) {
                return $candidate;
            }
        }
        return $this->nameGenerator->getRandomNationality();
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Enum\Country;
use App\Enum\NationalityGroup;
use App\Repository\PlayerRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Service\CountryContent\NamePoolProvider;

/**
 * The rows behind the nationality picker on the player and staff pool screens.
 *
 * ONE GROUPED QUERY PER TABLE, assembled here into what the template renders. The picker
 * needs a count beside every nationality, and the two alternatives were both bad: calling
 * the existing per-nationality scalars in a loop is about seventy queries a page load, and
 * getPoolBreakdown() gets its nationality facet by pulling the whole pool through
 * getArrayResult() and tallying in PHP for a cached stats endpoint.
 *
 * EVERY NATIONALITY IS LISTED, not just the ones with somebody in the pool — the whole point
 * of the picker is to generate for the empty ones. The queries only return nationalities that
 * have rows, so misses become 0 here.
 *
 * THE DEMONYM IS THE KEY, not the country code. It is what the generate-chunk endpoints take
 * as `nationality`, and what player.nationality / staff.nationality / scout.nationality store.
 * The code rides along only for the flag.
 */
final class NationalityPoolCounts
{
    public function __construct(
        private readonly PlayerRepository $playerRepository,
        private readonly StaffRepository  $staffRepository,
        private readonly ScoutRepository  $scoutRepository,
        private readonly NamePoolProvider $namePools,
    ) {}

    /** @return array<string, list<array{nationality: string, code: string, count: int, hasNamePool: bool}>> */
    public function forPlayers(): array
    {
        return $this->group($this->playerRepository->countPoolByNationality());
    }

    /**
     * Staff and scouts summed per nationality.
     *
     * Summed because the picker is one list: a job is role × nationality, and the operator is
     * choosing the nationality half. The per-role split the six tiles need is staffByRole().
     *
     * @return array<string, list<array{nationality: string, code: string, count: int, hasNamePool: bool}>>
     */
    public function forStaff(): array
    {
        $totals = [];

        foreach ($this->staffRepository->countPoolByRoleAndNationality() as $byNationality) {
            foreach ($byNationality as $nationality => $n) {
                $totals[$nationality] = ($totals[$nationality] ?? 0) + $n;
            }
        }

        foreach ($this->scoutRepository->countPoolByNationality() as $nationality => $n) {
            $totals[$nationality] = ($totals[$nationality] ?? 0) + $n;
        }

        return $this->group($totals);
    }

    /**
     * Per-role counts for the six tiles, with scouts as a seventh key.
     *
     * Keyed by the `type` the generate-chunk endpoint takes, NOT by the keys the counts JSON
     * uses — those differ (staffCoach vs coaches, staffFacilityMgr vs facility_managers) and
     * each template used to carry its own map to bridge them.
     *
     * @return array<string, array<string, int>> generate type => nationality => count
     */
    public function staffByRole(): array
    {
        $out = [];

        foreach ($this->staffRepository->countPoolByRoleAndNationality() as $role => $byNationality) {
            $out[self::GENERATE_TYPE_BY_ROLE[$role] ?? $role] = $byNationality;
        }

        $out['scouts'] = $this->scoutRepository->countPoolByNationality();

        return $out;
    }

    /** staff.role backing value => the `type` admin_staff_pool_generate_chunk expects. */
    private const GENERATE_TYPE_BY_ROLE = [
        'coach'                => 'coaches',
        'manager'              => 'managers',
        'director_of_football' => 'directors',
        'facility_manager'     => 'facility_managers',
        'chairman'             => 'chairmen',
    ];

    /**
     * @param  array<string, int> $counts nationality => count, misses meaning zero
     * @return array<string, list<array{nationality: string, code: string, count: int, hasNamePool: bool}>>
     */
    private function group(array $counts): array
    {
        $out = [];

        // HOISTED, and for the same reason this class's docblock gives for the counts above:
        // asking per nationality inside the loop is seventy-one queries a page load, which is
        // the N+1 this file exists to avoid. One query answers all of them.
        $hasPool = $this->namePools->hasMap();

        foreach (Country::nationalityMap() as $code => $nationality) {
            $group = NationalityGroup::forCountry(Country::from($code));

            // A country with no region at all. NationalityGroupTest says there are none, so
            // this is for the deploy where somebody adds one and forgets the other half.
            $heading = $group?->label() ?? 'Other';

            $out[$heading][] = [
                'nationality' => $nationality,
                // NOT isoAlpha2(). public/assets/flags/ is keyed on `value` so England,
                // Scotland and Wales each get their own flag rather than the Union Jack —
                // Country::isoAlpha2()'s docblock says so in as many words.
                'code'        => $code,
                'count'       => $counts[$nationality] ?? 0,
                // False means generation falls back to English names for this nationality.
                'hasNamePool' => $hasPool[$nationality] ?? false,
            ];
        }

        // Headings in enum order, names A-Z inside each. The picker re-sorts client-side
        // (REGION / A-Z / THINNEST); this is just a stable starting point.
        $ordered = [];

        foreach (NationalityGroup::ordered() as $group) {
            $heading = $group->label();

            if (isset($out[$heading])) {
                usort($out[$heading], static fn (array $a, array $b): int => $a['nationality'] <=> $b['nationality']);
                $ordered[$heading] = $out[$heading];
            }
        }

        if (isset($out['Other'])) {
            $ordered['Other'] = $out['Other'];
        }

        return $ordered;
    }
}

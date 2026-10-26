<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\StarterConfig;
use App\Enum\Country;
use App\Enum\PlayerPosition;
use App\Enum\StaffRole;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;

/**
 * Answers "can a new club in country X actually be given a starter pack right now?"
 * without creating a club or consuming a single pool entity.
 *
 * It exists because the failure it diagnoses is invisible from the pool totals an admin
 * screen shows. A pool can hold 20,000 players and still serve nobody: the draw filters on
 * ability band AND first-team age at once, and those two conditions can be individually
 * well-stocked while their intersection is empty — which is exactly what happens when a
 * tier's configured ability band sits inside the youth ability ceiling
 * (PoolConfig::abilityCeilingFractionForAge()), since every player low enough to match is
 * too young to be drawn. So this reports the draw as a FUNNEL — position, then + ability,
 * then + age, then + nationality — because the number that matters is the step where the
 * count collapses, not the zero at the end.
 *
 * It deliberately mirrors StarterPackService::initialize() rather than re-deriving it:
 * the ability band comes from StarterPackService::resolveAbilityRange(), the position
 * split from the same WorldPackSnapshotBuilder::distributeByPosition(), the age floor from
 * the same PoolConfig field. A checker free to drift from the code it checks is worse than
 * no checker.
 */
final class StarterPackDiagnosticService
{
    /** How many squads' worth of eligible players the suggested band is sized to cover. */
    private const SUGGESTION_SQUAD_MULTIPLE = 3;

    public function __construct(
        private readonly PlayerRepository         $playerRepository,
        private readonly StaffRepository          $staffRepository,
        private readonly ScoutRepository          $scoutRepository,
        private readonly StarterConfigRepository  $starterConfigRepository,
        private readonly PoolConfigRepository     $poolConfigRepository,
        private readonly WorldPackSnapshotBuilder $snapshotBuilder,
        private readonly StarterPackService       $starterPackService,
    ) {}

    /**
     * @param string   $countryCode A Country case value, e.g. "US"
     * @param int|null $tier        League tier to test; defaults to the tier a brand-new
     *                              club is drawn at (it has no league yet at that point)
     *
     * @return array<string, mixed>
     */
    public function diagnose(string $countryCode, ?int $tier = null): array
    {
        $starterConfig = $this->starterConfigRepository->getConfig();
        $poolConfig    = $this->poolConfigRepository->getConfig();

        $countryCode = strtoupper(trim($countryCode));
        $country     = Country::tryFrom($countryCode);
        $nationality = $country?->nationality();
        $tier        = $tier ?? StarterPackService::DEFAULT_STARTER_TIER;
        $ability     = $this->starterPackService->resolveAbilityRange($countryCode, $tier);

        // Same expression as the real draw: an age floor becomes a date because
        // date_of_birth is the column that exists.
        $firstTeamAgeMin = $poolConfig->getPlayerFirstTeamAgeMin();
        $bornOnOrBefore  = (new \DateTimeImmutable('today'))->modify("-{$firstTeamAgeMin} years");

        $requestedTotal = $starterConfig->getStarterPlayerCount();
        $posCounts      = $this->snapshotBuilder->distributeByPosition($requestedTotal, $poolConfig);

        $positions        = [];
        $availableTotal   = 0;
        $bandHoldsOnlyKids = false;

        foreach ($posCounts as $posValue => $requested) {
            $position = PlayerPosition::from((string) $posValue);

            $inPosition  = $this->playerRepository->countPoolMatching(position: $position);
            $inBand      = $this->playerRepository->countPoolMatching(
                position: $position, abilityMin: $ability['min'], abilityMax: $ability['max'],
            );
            $inBandAged  = $this->playerRepository->countPoolMatching(
                position: $position, abilityMin: $ability['min'], abilityMax: $ability['max'],
                bornOnOrBefore: $bornOnOrBefore,
            );
            $native      = $nationality === null ? 0 : $this->playerRepository->countPoolMatching(
                position: $position, abilityMin: $ability['min'], abilityMax: $ability['max'],
                nationality: $nationality, bornOnOrBefore: $bornOnOrBefore,
            );
            $aged        = $this->playerRepository->countPoolMatching(
                position: $position, bornOnOrBefore: $bornOnOrBefore,
            );

            // The foreign fallback excludes the sentinel nationality "__none__", i.e. it
            // excludes nobody — so everything age-eligible in the band is reachable, with
            // natives simply drawn first.
            $available = $inBandAged;
            $availableTotal += min($requested, $available);

            if ($inBand > 0 && $inBandAged === 0) {
                $bandHoldsOnlyKids = true;
            }

            $positions[] = [
                'position'   => $position->value,
                'requested'  => $requested,
                'available'  => $available,
                'native'     => $native,
                'foreign'    => max(0, $inBandAged - $native),
                'shortfall'  => max(0, $requested - $available),
                'ok'         => $available >= $requested,
                'funnel'     => [
                    ['label' => 'In pool',                                               'count' => $inPosition],
                    ['label' => sprintf('+ ability %d–%d', $ability['min'], $ability['max']), 'count' => $inBand],
                    ['label' => sprintf('+ aged %d+', $firstTeamAgeMin),                 'count' => $inBandAged],
                    ['label' => '+ nationality ' . ($nationality ?? '—'),                'count' => $native],
                ],
                // Context for a band that came back empty: how many age-eligible players
                // of this position exist at ANY ability, and what abilities they carry.
                'ageEligible' => [
                    'count'      => $aged,
                    'abilityMin' => $this->lowestEligibleAbility($bornOnOrBefore, $position),
                ],
            ];
        }

        $staff = [];
        foreach ($this->staffRequirements($starterConfig) as $roleValue => $requested) {
            $role   = StaffRole::from($roleValue);
            $native = $nationality === null ? 0 : $this->staffRepository->countInPoolByRole($role, $nationality);
            $any    = $this->staffRepository->countInPoolByRole($role);

            $staff[] = [
                'role'      => $role->value,
                'requested' => $requested,
                'native'    => $native,
                'available' => $any,
                'shortfall' => max(0, $requested - $any),
                'ok'        => $any >= $requested,
            ];
        }

        $scoutsRequested = $starterConfig->getStarterScoutCount();
        $scouts = [
            'requested' => $scoutsRequested,
            'native'    => $nationality === null ? 0 : $this->scoutRepository->countInPool($nationality),
            'available' => $this->scoutRepository->countInPool(),
        ];
        $scouts['shortfall'] = max(0, $scoutsRequested - $scouts['available']);
        $scouts['ok']        = $scouts['available'] >= $scoutsRequested;

        // "Blocked" is not merely a thin squad: StarterPackService throws
        // InsufficientStarterPoolException when the player draw comes back completely
        // empty, which surfaces to the client as a failed club initialisation.
        $verdict = match (true) {
            $availableTotal === 0                                                      => 'blocked',
            $availableTotal < $requestedTotal                                          => 'degraded',
            array_filter($staff, static fn (array $r): bool => !$r['ok']) !== []       => 'degraded',
            !$scouts['ok']                                                             => 'degraded',
            default                                                                    => 'ok',
        };

        $suggestion = ($availableTotal < $requestedTotal)
            ? $this->suggestBand($bornOnOrBefore, $requestedTotal)
            : null;

        return [
            'country'          => $countryCode,
            'countryLabel'     => $country?->label() ?? $countryCode,
            'countryKnown'     => $country !== null,
            'countryEnabled'   => in_array($countryCode, $starterConfig->getEnabledCountries(), true),
            'nationality'      => $nationality,
            'tier'             => $tier,
            'tierIsDefault'    => $tier === StarterPackService::DEFAULT_STARTER_TIER,
            'ability'          => $ability,
            'firstTeamAgeMin'  => $firstTeamAgeMin,
            'bornOnOrBefore'   => $bornOnOrBefore,
            'poolTotal'        => $this->playerRepository->countPoolMatching(),
            'poolAgeEligible'  => $this->playerRepository->countPoolMatching(bornOnOrBefore: $bornOnOrBefore),
            'poolInBand'       => $this->playerRepository->countPoolMatching(
                abilityMin: $ability['min'], abilityMax: $ability['max'],
            ),
            'playersRequested' => $requestedTotal,
            'playersAvailable' => $availableTotal,
            'positions'        => $positions,
            'staff'            => $staff,
            'scouts'           => $scouts,
            'verdict'          => $verdict,
            // Exposed, not just used for findings: StarterPoolAuditService needs to tell a
            // band that is merely under-stocked (queue a top-up) from one whose every
            // member is too young to be drawn (generating more changes nothing).
            'bandHoldsOnlyKids' => $bandHoldsOnlyKids,
            'suggestedBand'    => $suggestion,
            'findings'         => $this->buildFindings(
                $verdict, $country, $countryCode, $nationality, $tier, $ability,
                $firstTeamAgeMin, $bandHoldsOnlyKids, $positions, $staff, $scouts,
                $suggestion, $starterConfig->getEnabledCountries(),
            ),
        ];
    }

    /** @return array<string, int> role value => how many the starter pack asks for */
    private function staffRequirements(StarterConfig $config): array
    {
        return [
            StaffRole::MANAGER->value              => $config->getStarterManagerCount(),
            StaffRole::COACH->value                => $config->getStarterCoachCount(),
            StaffRole::DIRECTOR_OF_FOOTBALL->value => $config->getStarterDirectorOfFootballCount(),
            StaffRole::FACILITY_MANAGER->value     => $config->getStarterFacilityManagerCount(),
            StaffRole::CHAIRMAN->value             => $config->getStarterChairmanCount(),
        ];
    }

    private function lowestEligibleAbility(\DateTimeImmutable $bornOnOrBefore, PlayerPosition $position): ?int
    {
        $abilities = $this->playerRepository->lowestEligibleAbilities($bornOnOrBefore, 1, $position);

        return $abilities[0] ?? null;
    }

    /**
     * A band that WOULD fill a squad, derived from what age-eligible players actually
     * carry: the bottom slice of their abilities, sized to a few squads so the draw still
     * has room to randomise. Nationality is deliberately ignored — the real draw falls
     * back to any nationality, so a band tuned to one country's players would be narrower
     * than it needs to be.
     *
     * @return array{min: int, max: int, sampled: int}|null
     */
    private function suggestBand(\DateTimeImmutable $bornOnOrBefore, int $squadSize): ?array
    {
        $abilities = $this->playerRepository->lowestEligibleAbilities(
            $bornOnOrBefore,
            max(1, $squadSize * self::SUGGESTION_SQUAD_MULTIPLE),
        );

        if ($abilities === []) {
            return null;
        }

        return ['min' => min($abilities), 'max' => max($abilities), 'sampled' => count($abilities)];
    }

    /**
     * Turns the raw counts into the sentences an operator can act on. Ordered most to
     * least severe, because the first line is the one that gets read.
     *
     * @param array<int, array<string, mixed>> $positions
     * @param array<int, array<string, mixed>> $staff
     * @param array<string, mixed>             $scouts
     * @param array{min: int, max: int, source: string} $ability
     * @param array{min: int, max: int, sampled: int}|null $suggestion
     * @param string[] $enabledCountries
     *
     * @return array<int, array{level: string, message: string}>
     */
    private function buildFindings(
        string $verdict,
        ?Country $country,
        string $countryCode,
        ?string $nationality,
        int $tier,
        array $ability,
        int $firstTeamAgeMin,
        bool $bandHoldsOnlyKids,
        array $positions,
        array $staff,
        array $scouts,
        ?array $suggestion,
        array $enabledCountries,
    ): array {
        $findings = [];

        if ($country === null) {
            $findings[] = [
                'level'   => 'danger',
                'message' => sprintf(
                    '"%s" is not a Country case, so no nationality could be resolved. The draw '
                    . 'falls back to matching nationality literally against "%s" and will only '
                    . 'ever fill from the foreign fallback.',
                    $countryCode,
                    $countryCode,
                ),
            ];
        }

        if ($verdict === 'blocked') {
            $findings[] = [
                'level'   => 'danger',
                'message' => 'No players at all match. Club initialisation will fail with '
                    . 'InsufficientStarterPoolException (nothing is consumed, so it is retriable '
                    . 'once the data is fixed).',
            ];
        }

        if ($bandHoldsOnlyKids) {
            $findings[] = [
                'level'   => 'danger',
                'message' => sprintf(
                    'The tier %d ability band %d–%d holds players, but every one of them is under '
                    . '%d and so excluded by the first-team age floor. That band sits inside the '
                    . 'youth ability ceiling (PoolConfig), which is the only thing that generates '
                    . 'abilities that low — so no adult can ever match it. Raise the band for this '
                    . 'tier on Starter Config, or lower PoolConfig.playerFirstTeamAgeMin.',
                    $tier,
                    $ability['min'],
                    $ability['max'],
                    $firstTeamAgeMin,
                ),
            ];
        }

        if ($suggestion !== null) {
            $findings[] = [
                'level'   => 'info',
                'message' => sprintf(
                    'The %d weakest age-eligible players in the pool carry ability %d–%d. A tier %d '
                    . 'band in that region would fill a squad from the data you actually have.',
                    $suggestion['sampled'],
                    $suggestion['min'],
                    $suggestion['max'],
                    $tier,
                ),
            ];
        }

        foreach ($positions as $row) {
            if ($row['ok']) {
                continue;
            }
            $findings[] = [
                'level'   => $row['available'] === 0 ? 'danger' : 'warning',
                'message' => sprintf(
                    '%s: %d of %d available%s.',
                    $row['position'],
                    $row['available'],
                    $row['requested'],
                    $row['ageEligible']['abilityMin'] !== null
                        ? sprintf(
                            ' (the weakest age-eligible %s in the pool is ability %d)',
                            $row['position'],
                            $row['ageEligible']['abilityMin'],
                        )
                        : ' (no age-eligible players of this position exist at all)',
                ),
            ];
        }

        // Only worth saying when there IS a squad to draw: with nothing matching at all,
        // "it would all come from the fallback" reads as though a fallback would save it.
        if ($nationality !== null && $verdict !== 'blocked') {
            $nativeTotal = array_sum(array_column($positions, 'native'));
            if ($nativeTotal === 0) {
                $findings[] = [
                    'level'   => 'warning',
                    'message' => sprintf(
                        'No %s players match, so the whole squad would come from the foreign '
                        . 'fallback.',
                        $nationality,
                    ),
                ];
            }
        }

        foreach ($staff as $row) {
            if ($row['ok']) {
                continue;
            }
            $findings[] = [
                'level'   => 'warning',
                'message' => sprintf('Staff %s: %d of %d in the pool.', $row['role'], $row['available'], $row['requested']),
            ];
        }

        if (!$scouts['ok']) {
            $findings[] = [
                'level'   => 'warning',
                'message' => sprintf('Scouts: %d of %d in the pool.', $scouts['available'], $scouts['requested']),
            ];
        }

        if ($enabledCountries !== [] && !in_array($countryCode, $enabledCountries, true)) {
            $findings[] = [
                'level'   => 'info',
                'message' => sprintf(
                    '%s is not in StarterConfig.enabledCountries, so clients cannot pick it even '
                    . 'if the pool can serve it.',
                    $countryCode,
                ),
            ];
        }

        if ($findings === []) {
            $findings[] = ['level' => 'success', 'message' => 'A full starter pack can be built for this country right now.'];
        }

        return $findings;
    }
}

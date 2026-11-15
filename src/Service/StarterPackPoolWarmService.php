<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\PlayerPosition;
use App\Repository\PoolConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Generates the players a (country, tier) starter pack actually needs, using the world
 * pack's generation call rather than the flat pool one.
 *
 * WHY THIS EXISTS. `app:pool:warm` tops the pool up from PoolConfig's single flat
 * distribution — one potential range, pool-wide ages, no tier anywhere. The starter pack
 * draw, meanwhile, asks for `currentAbility BETWEEN tier_min AND tier_max` AND
 * `date_of_birth <= today - firstTeamAgeMin` (StarterPackService::initialize()). Nothing in
 * the flat distribution aims at a tier band, so when a band came back empty, warming could
 * not fill it: it added more of the same distribution, which contains none of the band. The
 * admin page offered GENERATE as the remedy for a failure it was structurally incapable of
 * fixing, and an operator could press it all afternoon without moving the number.
 *
 * WorldPackClubGenerationService has had the answer all along. It generates for a known
 * (country, tier), passing that tier's ability band and PoolConfig::firstTeamAgeRange() into
 * PlayerGenerationService — which is why NPC clubs come out tier-appropriate while the pool
 * does not. This service makes the warm path do the same thing, through the same generator
 * (MarketPoolService::generatePlayersInBand()), so there is one generation process and the
 * tier is simply an argument to it.
 *
 * THE SHORTFALL COMES FROM THE CHECK ITSELF. Requirements are read from
 * StarterPackDiagnosticService rather than re-derived, so GENERATE fills exactly what RUN
 * CHECK reports short, per position. A second source of truth here would be free to drift
 * from the page it is meant to satisfy.
 */
class StarterPackPoolWarmService
{
    /**
     * Squads' worth of players to provision, not one.
     *
     * The starter draw CONSUMES pool rows — each new club takes its squad out. Generating
     * exactly the reported shortfall would make the check pass and then fail again for the
     * second player to start a game in that country, which is the same trap as before,
     * just one game later.
     */
    public const DEFAULT_SQUADS = 3;

    /** Refuses to generate more than this per position in one press, whatever the arithmetic says. */
    public const MAX_PER_POSITION = 400;

    public function __construct(
        private readonly StarterPackDiagnosticService $diagnostics,
        private readonly StarterPackService           $starterPack,
        private readonly PoolConfigRepository         $poolConfigRepository,
        private readonly MarketPoolService            $pool,
        private readonly LoggerInterface              $logger,
    ) {}

    /**
     * @return array{
     *     country: string, tier: int, nationality: string, squads: int,
     *     band: array{min: int, max: int, source: string},
     *     ageRange: array{min: int, max: int},
     *     positions: list<array{position: string, requested: int, available: int, target: int, deficit: int, generated: int, attempts: int, capped: bool}>,
     *     generated: int, attempts: int, shortOfTarget: bool
     * }
     */
    public function warmForTier(string $countryCode, int $tier, int $squads = self::DEFAULT_SQUADS): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $squads      = max(1, $squads);

        // The draw's own expression, deliberately: generated rows have to match the
        // nationality the draw asks for first, or they only ever arrive as foreign backfill.
        $nationality = ClubInitializationService::countryToNationality($countryCode) ?? $countryCode;

        $band     = $this->starterPack->resolveAbilityRange($countryCode, $tier);
        $ageRange = $this->poolConfigRepository->getConfig()->firstTeamAgeRange();

        // resolveAbilityRange() also reports WHERE the band came from, which belongs in the
        // operator's report and not in a generation argument.
        $abilityRange = ['min' => $band['min'], 'max' => $band['max']];
        $report   = $this->diagnostics->diagnose($countryCode, $tier);

        $positions     = [];
        $totalMade     = 0;
        $totalAttempts = 0;
        $short         = false;

        foreach ($report['positions'] as $row) {
            $position  = PlayerPosition::from((string) $row['position']);
            $requested = (int) $row['requested'];
            $available = (int) $row['available'];
            $target    = $requested * $squads;
            $deficit   = max(0, $target - $available);
            $capped    = $deficit > self::MAX_PER_POSITION;
            $toMake    = min($deficit, self::MAX_PER_POSITION);

            $made     = 0;
            $attempts = 0;

            if ($toMake > 0) {
                $result   = $this->pool->generatePlayersInBand($toMake, $position, $nationality, $abilityRange, $ageRange);
                $made     = count($result['players']);
                $attempts = $result['attempts'];
            }

            if ($made < $toMake) {
                $short = true;
            }

            $totalMade     += $made;
            $totalAttempts += $attempts;

            $positions[] = [
                'position'  => $position->value,
                'requested' => $requested,
                'available' => $available,
                'target'    => $target,
                'deficit'   => $deficit,
                'generated' => $made,
                'attempts'  => $attempts,
                'capped'    => $capped,
            ];
        }

        $this->logger->info('starter_pack.warm_for_tier', [
            'country'     => $countryCode,
            'tier'        => $tier,
            'nationality' => $nationality,
            'bandMin'     => $band['min'],
            'bandMax'     => $band['max'],
            'bandSource'  => $band['source'],
            'ageMin'      => $ageRange['min'],
            'ageMax'      => $ageRange['max'],
            'squads'      => $squads,
            'generated'   => $totalMade,
            'attempts'    => $totalAttempts,
            'shortOfTarget' => $short,
        ]);

        return [
            'country'       => $countryCode,
            'tier'          => $tier,
            'nationality'   => $nationality,
            'squads'        => $squads,
            'band'          => $band,
            'ageRange'      => $ageRange,
            'positions'     => $positions,
            'generated'     => $totalMade,
            'attempts'      => $totalAttempts,
            'shortOfTarget' => $short,
        ];
    }
}

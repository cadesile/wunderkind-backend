<?php

namespace App\Controller\Api;

use App\Enum\Country;
use App\Repository\StarterConfigRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public endpoint — no JWT required.
 * Called by the client before the user has credentials,
 * to know how to initialise a fresh club.
 *
 * The `tutorial` block's contract, validation and admin workflow:
 * docs/api/setup-tutorial.md.
 */
#[Route('/api')]
class StarterConfigController extends AbstractController
{
    public function __construct(
        private readonly StarterConfigRepository $starterConfigRepository,
    ) {}

    #[Route('/starter-config', name: 'api_starter_config', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $config = $this->starterConfigRepository->getConfig();

        // Resolve each enabled code to its display label via the Country enum
        // (the single source of truth for codes/labels — see its class docblock)
        // so the client's country picker never needs its own hardcoded copy of
        // this list to stay in sync with what admins enable.
        $enabledCountryOptions = [];
        foreach ($config->getEnabledCountries() as $code) {
            $country = Country::tryFrom($code);
            if ($country !== null) {
                $enabledCountryOptions[] = ['code' => $country->value, 'label' => $country->label()];
            }
        }

        return $this->json([
            'startingBalance'    => $config->getStartingBalance(),
            // The ACCOUNT's opening credit range, in pence — what land and stadiums are charged
            // against, as opposed to `startingBalance` above, which is the club's own kitty.
            // The range is served (not the resolved figure) because this endpoint is PUBLIC and
            // unauthenticated: there is no account to resolve for. The actual grant arrives on
            // the ledger when the owner avatar is created; read it from GET /api/account/ledger.
            'userStartingBalanceMinPence' => $config->getUserStartingBalanceMinPence(),
            'userStartingBalanceMaxPence' => $config->getUserStartingBalanceMaxPence(),
            // The CEILING only. An account's spend against it is per-account and therefore
            // cannot live on this shared, cached read — see GET /api/account/enrichment-quota,
            // which returns both. 0 means unlimited.
            'maxEnrichmentRequestsPerUser' => $config->getMaxEnrichmentRequestsPerUser(),
            // ADDITIVE, 2026-11-05, for the setup flow's BUILD and FUND steps.
            //
            // `quizBonusMultiplier` is published so the quiz screen can SAY what a perfect
            // score is worth as a multiple ("double your grant"), not so the client can compute
            // the amount — it cannot, because the grant is a per-account figure resolved from
            // the range above. The paid figure always comes from the ledger. 0 means the bonus
            // is switched off.
            'quizBonusMultiplier'        => $config->getQuizBonusMultiplier(),
            // The stake floor. **0 means no minimum**, which is the default and the agreed
            // behaviour: the player may stake any part of their balance, up to all of it.
            'clubFundingMinimumPence'    => $config->getClubFundingMinimumPence(),
            // How many weeks the funding screen should project running costs over. The CLIENT
            // does that projection: the server has no roster to read wages from (hired staff
            // and players are deleted from the pool on assign), so it sums staff and scout
            // `weeklySalary` from GET /api/market/data itself. POST /api/club/fund returns the
            // one running cost the server does hold, facility upkeep.
            'clubFundingProjectionWeeks' => $config->getClubFundingProjectionWeeks(),
            'starterPlayerCount' => $config->getStarterPlayerCount(),
            'starterManagerCount'             => $config->getStarterManagerCount(),
            'starterCoachCount'               => $config->getStarterCoachCount(),
            'starterScoutCount'               => $config->getStarterScoutCount(),
            'starterDirectorOfFootballCount'  => $config->getStarterDirectorOfFootballCount(),
            'starterFacilityManagerCount'     => $config->getStarterFacilityManagerCount(),
            'starterChairmanCount'            => $config->getStarterChairmanCount(),
            'starterSponsorTier' => $config->getStarterSponsorTier(),
            'starterClubTier'    => $config->getStarterClubTier(),
            'enabledCountries'   => $config->getEnabledCountries(),
            'enabledCountryOptions' => $enabledCountryOptions,
            'leagueAbilityRanges' => $config->getLeagueAbilityRanges(),
            'defaultFacilities'   => $config->getDefaultFacilities(),
            'fanBaseRanges'              => $config->getFanBaseRanges(),
            'fanBasePromotionIncrease'   => $config->getFanBasePromotionIncrease(),
            'fanBaseRelegationDecrease'  => $config->getFanBaseRelegationDecrease(),
            // Optional first-run guidance for the app's new-game setup flow. Served
            // verbatim: the client's `parseTutorial` validates it item by item and never
            // throws, dropping anything it does not like, so a malformed block degrades to
            // "no tutorial" rather than blocking the one route a new account cannot skip.
            // An empty array is that state. Copy keys are `ngs.tut.*`.
            // See docs/api/setup-tutorial.md.
            'tutorial'                   => $config->getSetupTutorialConfig(),
        ]);
    }
}

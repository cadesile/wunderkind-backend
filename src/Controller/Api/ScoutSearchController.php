<?php

namespace App\Controller\Api;

use App\Enum\Country;
use App\Enum\PlayerPosition;
use App\Enum\Tier;
use App\Repository\NpcClubRepository;
use App\Repository\PlayerRepository;
use App\Service\PlayerBrowseSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/scout')]
class ScoutSearchController extends AbstractController
{
    private const MAX_AMOUNT = 200;

    /** Tier ordering used to resolve "one tier above". */
    private const TIER_ABOVE = [
        'local'    => 'regional',
        'regional' => 'national',
        'national' => 'elite',
        'elite'    => null, // already at top — no tier above
    ];

    #[Route('/foreign-clubs', name: 'api_scout_foreign_clubs', methods: ['GET'])]
    public function foreignClubs(Request $request, NpcClubRepository $npcClubRepo): JsonResponse
    {
        $country      = strtoupper($request->query->get('country', 'EN'));
        $rep          = $request->query->get('rep') ?: null;
        $limitPerTier = max(1, min(10, (int) $request->query->get('limit_per_tier', 3)));

        $clubs = $npcClubRepo->findForeignClubs($country, $rep, $limitPerTier);

        return $this->json([
            'country' => $country,
            'clubs'   => $clubs,
        ]);
    }

    #[Route('/search', name: 'api_scout_search', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function search(Request $request, PlayerRepository $playerRepo, PlayerBrowseSerializer $serializer): JsonResponse
    {
        // ── rep ──────────────────────────────────────────────────────────────
        $repParam = strtolower((string) $request->query->get('rep', 'local'));
        $tier = Tier::tryFrom($repParam);
        if ($tier === null) {
            return $this->json(
                ['error' => 'Invalid rep value. Must be one of: local, regional, national, elite.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // ── position (optional) ───────────────────────────────────────────────
        $position = null;
        $posParam = $request->query->get('position');
        if ($posParam !== null) {
            $position = PlayerPosition::tryFrom(strtoupper($posParam));
            if ($position === null) {
                return $this->json(
                    ['error' => 'Invalid position value. Must be one of: GK, DEF, MID, ATT.'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        // ── nationality (optional) ────────────────────────────────────────────
        $nationality = $request->query->get('nationality') ?: null;

        // ── ignore_country (optional) — excludes this country's nationality ───
        $excludeNationality = null;
        $ignoreCountryParam = $request->query->get('ignore_country');
        if ($ignoreCountryParam !== null && trim($ignoreCountryParam) !== '') {
            $ignoreCountry = Country::tryFrom(strtoupper(trim($ignoreCountryParam)));
            if ($ignoreCountry === null) {
                return $this->json(
                    ['error' => "Unknown country code '{$ignoreCountryParam}'."],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
            $excludeNationality = $ignoreCountry->nationality();
        }

        // ── age_range (optional, format "17-25") ─────────────────────────────
        $ageMin = null;
        $ageMax = null;
        $ageRangeParam = $request->query->get('age_range');
        if ($ageRangeParam !== null) {
            $parts = explode('-', $ageRangeParam, 2);
            if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                return $this->json(
                    ['error' => 'Invalid age_range format. Expected "min-max", e.g. 17-25.'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $ageMin = (int) $parts[0];
            $ageMax = (int) $parts[1];
            if ($ageMin > $ageMax) {
                return $this->json(
                    ['error' => 'age_range min must be less than or equal to max.'],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        // ── amount ────────────────────────────────────────────────────────────
        $amount = (int) $request->query->get('amount', 20);
        $amount = max(1, min($amount, self::MAX_AMOUNT));

        // ── ability (0–100, percentage of amount drawn from tier above) ───────
        $abilityPct = (int) $request->query->get('ability', 0);
        $abilityPct = max(0, min(100, $abilityPct));

        // ── Resolve ability split ─────────────────────────────────────────────
        $aboveTierSlug = self::TIER_ABOVE[$tier->value];
        $aboveTier     = $aboveTierSlug !== null ? Tier::from($aboveTierSlug) : null;

        // If there is no tier above (elite), force all results from base tier
        if ($aboveTier === null) {
            $abilityPct = 0;
        }

        $aboveCount = (int) ceil($amount * $abilityPct / 100);
        $baseCount  = $amount - $aboveCount;

        // ── Fetch base-tier players ───────────────────────────────────────────
        [$baseMin, $baseMax] = $tier->scoreRange();
        $basePlayers = $playerRepo->findForScoutSearch(
            $baseMin, $baseMax, $position, $nationality, $ageMin, $ageMax, $baseCount, $excludeNationality
        );

        // ── Fetch above-tier players ──────────────────────────────────────────
        $abovePlayers = [];
        if ($aboveCount > 0 && $aboveTier !== null) {
            [$aboveMin, $aboveMax] = $aboveTier->scoreRange();
            $abovePlayers = $playerRepo->findForScoutSearch(
                $aboveMin, $aboveMax, $position, $nationality, $ageMin, $ageMax, $aboveCount, $excludeNationality
            );
        }

        // Merge and shuffle so above-tier players aren't always grouped at the end
        $all = array_merge($basePlayers, $abovePlayers);
        shuffle($all);

        return $this->json([
            'rep'           => $tier->value,
            'amount'        => count($all),
            'ability'       => $abilityPct,
            'ignoreCountry' => $excludeNationality !== null ? strtoupper(trim($ignoreCountryParam)) : null,
            'players'       => array_map($serializer->serialize(...), $all),
        ]);
    }
}

<?php

namespace App\Controller\Api;

use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\Tier;
use App\Repository\PlayerRepository;
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

    #[Route('/search', name: 'api_scout_search', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function search(Request $request, PlayerRepository $playerRepo): JsonResponse
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
            $baseMin, $baseMax, $position, $nationality, $ageMin, $ageMax, $baseCount
        );

        // ── Fetch above-tier players ──────────────────────────────────────────
        $abovePlayers = [];
        if ($aboveCount > 0 && $aboveTier !== null) {
            [$aboveMin, $aboveMax] = $aboveTier->scoreRange();
            $abovePlayers = $playerRepo->findForScoutSearch(
                $aboveMin, $aboveMax, $position, $nationality, $ageMin, $ageMax, $aboveCount
            );
        }

        // Merge and shuffle so above-tier players aren't always grouped at the end
        $all = array_merge($basePlayers, $abovePlayers);
        shuffle($all);

        return $this->json([
            'rep'      => $tier->value,
            'amount'   => count($all),
            'ability'  => $abilityPct,
            'players'  => array_map($this->serializePlayer(...), $all),
        ]);
    }

    private function serializePlayer(Player $p): array
    {
        return [
            'id'                => $p->getId()->toRfc4122(),
            'firstName'         => $p->getFirstName(),
            'lastName'          => $p->getLastName(),
            'dateOfBirth'       => $p->getDateOfBirth()->format('Y-m-d'),
            'nationality'       => $p->getNationality(),
            'position'          => $p->getPosition()->value,
            'potential'         => $p->getPotential(),
            'currentAbility'    => $p->getCurrentAbility(),
            'contractValue'     => $p->getContractValue(),
            'tier'              => Tier::fromScore($p->getCurrentAbility())->value,
            'recruitmentSource' => $p->getRecruitmentSource()->value,
            'pace'              => $p->getPace(),
            'technical'         => $p->getTechnical(),
            'vision'            => $p->getVision(),
            'power'             => $p->getPower(),
            'stamina'           => $p->getStamina(),
            'heart'             => $p->getHeart(),
            'overall'           => $p->getOverall(),
            'physical'          => [
                'height' => $p->getHeight(),
                'weight' => $p->getWeight(),
            ],
            'agent'             => $p->getAgent() ? [
                'id'             => $p->getAgent()->getId()->toRfc4122(),
                'name'           => $p->getAgent()->getName(),
                'commissionRate' => $p->getAgent()->getCommissionRate(),
            ] : null,
            'personality'       => [
                'determination'   => $p->getPersonality()->getDetermination(),
                'professionalism' => $p->getPersonality()->getProfessionalism(),
                'ambition'        => $p->getPersonality()->getAmbition(),
                'loyalty'         => $p->getPersonality()->getLoyalty(),
                'adaptability'    => $p->getPersonality()->getAdaptability(),
                'pressure'        => $p->getPersonality()->getPressure(),
                'temperament'     => $p->getPersonality()->getTemperament(),
                'consistency'     => $p->getPersonality()->getConsistency(),
            ],
            'guardians'         => array_map(fn($g) => [
                'id'            => $g->getId()->toRfc4122(),
                'firstName'     => $g->getFirstName(),
                'lastName'      => $g->getLastName(),
                'dateOfBirth'   => $g->getDateOfBirth()?->format('Y-m-d'),
                'gender'        => $g->getGender(),
                'demandLevel'   => $g->getDemandLevel(),
                'loyaltyToClub' => $g->getLoyaltyToClub(),
                'contactEmail'  => $g->getContactEmail(),
            ], $p->getGuardians()->toArray()),
        ];
    }
}

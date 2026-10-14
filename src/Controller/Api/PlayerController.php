<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\Country;
use App\Repository\PlayerRepository;
use App\Service\PlayerBrowseSerializer;
use App\Service\PlayerSnapshotSerializer;
use App\Service\YouthPoolService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/players')]
class PlayerController extends AbstractController
{
    private const DEFAULT_AMOUNT = 20;
    private const MAX_AMOUNT     = 200;

    /**
     * GET /api/players/foreign?country=<code>&amount=<n>
     *
     * Random pool draw of players whose nationality does NOT match the given country code —
     * e.g. ?country=EN returns players who are anything but English. `country` is the
     * account's own club country, passed by the client (not inferred server-side), same
     * explicit-param convention as GET /api/scout/search.
     */
    #[Route('/foreign', name: 'api_players_foreign', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function foreign(Request $request, PlayerRepository $playerRepo, PlayerBrowseSerializer $serializer): JsonResponse
    {
        $countryParam = $request->query->get('country');
        if ($countryParam === null || trim($countryParam) === '') {
            return $this->json(['error' => 'country is required.'], Response::HTTP_BAD_REQUEST);
        }

        $country = Country::tryFrom(strtoupper(trim($countryParam)));
        if ($country === null) {
            return $this->json(
                ['error' => "Unknown country code '{$countryParam}'."],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $amount = (int) $request->query->get('amount', self::DEFAULT_AMOUNT);
        $amount = max(1, min($amount, self::MAX_AMOUNT));

        $players = $playerRepo->findForeign($country->nationality(), $amount);

        return $this->json([
            'country' => $country->value,
            'amount'  => count($players),
            'players' => array_map($serializer->serialize(...), $players),
        ]);
    }

    /**
     * GET /api/players/youth?country=<code>&limit=<n>
     *
     * Draws youth players (aged 12-14) from the existing pool, weighted towards the given
     * country and spread across all four reputation tiers so the device can match players
     * to any club in the pyramid itself. There is deliberately no `tier` parameter — see
     * YouthPoolService, and docs/api/players-youth.md for the full contract.
     *
     * Read-only and NON-CONSUMING, like /foreign: nothing is deleted or reserved, so two
     * devices can legitimately receive the same player id. Clients must upsert.
     *
     * `distribution` in the response is the REALISED spread, not the target. The pool is
     * the supply, so an under-seeded pool returns fewer than `limit` with the gap visible
     * there — an empty shelf, not an error.
     */
    #[Route('/youth', name: 'api_players_youth', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function youth(
        Request $request,
        YouthPoolService $youthPool,
        PlayerSnapshotSerializer $serializer,
    ): JsonResponse {
        $countryParam = $request->query->get('country');
        if ($countryParam === null || trim($countryParam) === '') {
            return $this->json(['error' => 'country is required.'], Response::HTTP_BAD_REQUEST);
        }

        $country = Country::tryFrom(strtoupper(trim($countryParam)));
        if ($country === null) {
            return $this->json(
                ['error' => "Unknown country code '{$countryParam}'."],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $limit = (int) $request->query->get('limit', YouthPoolService::DEFAULT_LIMIT);
        $limit = max(1, min($limit, YouthPoolService::MAX_LIMIT));

        $result = $youthPool->draw($country->nationality(), $limit);

        return $this->json([
            'country'      => $country->value,
            'amount'       => count($result['players']),
            'ageRange'     => ['min' => YouthPoolService::MIN_AGE, 'max' => YouthPoolService::MAX_AGE],
            'distribution' => [
                'requested'          => $result['requested'],
                'returned'           => count($result['players']),
                'poolSize'           => $result['poolSize'],
                'byPotentialTier'    => $result['tierCounts'],
                'nationalityMatched' => $result['nationalityMatched'],
            ],
            'players' => array_map(
                static fn ($p) => $serializer->serialize($p, withGuardians: true, isYouth: true),
                $result['players'],
            ),
        ]);
    }
}

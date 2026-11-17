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
    public function foreign(Request $request, PlayerRepository $playerRepo, PlayerBrowseSerializer $serializer, \App\Service\ClubResolver $clubResolver): JsonResponse
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

        // THE TIER IS RESOLVED SERVER-SIDE even though the country is a parameter, and the
        // asymmetry is deliberate: `country` SELECTS which players to show (anything but
        // English), so the client has to say which it means. The tier only PRICES them, and a
        // client-supplied price anchor would be a client-supplied discount.
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $tier = $clubResolver->resolveFromRequest($user)?->getCurrentLeague()?->getTier();

        return $this->json([
            'country' => $country->value,
            'amount'  => count($players),
            'players' => array_map($serializer->serializerFor($country->value, $tier), $players),
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
        \App\Service\Economy\ServeTimePlayerPricer $pricer,
        \App\Service\ClubResolver $clubResolver,
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

        // ── Serve-time pricing, which this endpoint was missing ───────────────────────────
        //
        // A youth player is a SHARED-POOL row: one row served to every country at every tier,
        // so its `contractValue` is stored at the legacy figure and has to be priced against
        // the BROWSING club's economy when it is served. `GET /api/scout/search` and
        // `GET /api/players/foreign` have done that since 2026-11-12 via
        // PlayerBrowseSerializer; this endpoint serializes through PlayerSnapshotSerializer
        // instead, so it never reached the pricing and served the stored legacy number — the
        // one remaining surface where a pool player escaped the economy.
        //
        // The club's own country and tier, NOT the `?country=` filter, which selects which
        // NATIONALITY of youth to draw. Those are different questions and conflating them would
        // price a club's wage bill by where it was shopping.
        //
        // The pricer is applied over this endpoint's OWN serializer call rather than by
        // switching to PlayerBrowseSerializer: that class is a `withGuardians: true` preset and
        // nothing else, and this endpoint already passes `withGuardians: true` plus
        // `isYouth: true`, which the preset cannot express. Only the wage was missing.
        $club     = $clubResolver->resolveFromRequest($this->getUser());
        $clubTier = $club?->getCurrentLeague()?->getTier();

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
                fn ($p) => $pricer->price(
                    $serializer->serialize($p, withGuardians: true, isYouth: true),
                    $p,
                    $club?->getCountry(),
                    $clubTier,
                ),
                $result['players'],
            ),
        ]);
    }
}

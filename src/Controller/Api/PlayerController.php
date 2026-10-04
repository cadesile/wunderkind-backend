<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\Country;
use App\Repository\PlayerRepository;
use App\Service\PlayerBrowseSerializer;
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
}

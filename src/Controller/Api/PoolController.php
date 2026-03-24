<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\RecruitmentSource;
use App\Repository\PlayerRepository;
use App\Service\AcademyInitializationService;
use App\Service\MarketPoolService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/pool')]
class PoolController extends AbstractController
{
    /**
     * Ensure at least `min` unassigned players of the given nationality exist in the pool.
     * Generates the deficit on-demand if the pool is short.
     * Safe to call repeatedly — idempotent when the pool is already full.
     *
     * POST /api/pool/ensure
     * Body: { "countryCode": "EN", "min": 10 }
     */
    #[Route('/ensure', name: 'api_pool_ensure', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function ensure(
        Request          $request,
        PlayerRepository $playerRepo,
        MarketPoolService $pool,
    ): JsonResponse {
        $body        = json_decode($request->getContent(), true) ?? [];
        $countryCode = $body['countryCode'] ?? null;
        $min         = max(1, min(50, (int) ($body['min'] ?? 10)));

        if (!is_string($countryCode) || $countryCode === '') {
            return $this->json(['error' => 'countryCode is required.'], Response::HTTP_BAD_REQUEST);
        }

        $nationality = AcademyInitializationService::countryToNationality($countryCode);

        if ($nationality === null) {
            return $this->json(
                ['error' => "Unknown country code: {$countryCode}"],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $existing = $playerRepo->findInPool($min, $nationality);
        $inPool   = count($existing);
        $deficit  = max(0, $min - $inPool);

        if ($deficit > 0) {
            $pool->generatePlayers($deficit, null, RecruitmentSource::YOUTH_INTAKE, $nationality);
        }

        return $this->json([
            'nationality'   => $nationality,
            'min'           => $min,
            'alreadyInPool' => $inPool,
            'generated'     => $deficit,
        ]);
    }
}

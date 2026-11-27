<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Location\PlotClaim;
use App\Entity\User;
use App\Service\Club\ClubLiquidationService;
use App\Service\ClubResolver;
use App\Repository\GameConfigRepository;
use App\Repository\Location\PlotClaimRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `POST /api/club/liquidate` — the app reports a game over for the club named by `X-Club-Id`.
 *
 * The device has already decided (see ClubLiquidationService). The answer lists the owner's
 * plots after the liquidation, so the game-over screen can offer RESTART HERE / SELL BACK
 * without a second request. Idempotent: a replay answers `liquidated: true` with the same dates.
 */
#[Route('/api/club')]
#[IsGranted('ROLE_CLUB')]
class ClubLiquidationController extends AbstractController
{
    public function __construct(
        private readonly ClubResolver           $clubResolver,
        private readonly ClubLiquidationService $liquidation,
        private readonly PlotClaimRepository    $claimRepository,
        private readonly GameConfigRepository   $configRepository,
    ) {}

    #[Route('/liquidate', name: 'api_club_liquidate', methods: ['POST'])]
    public function liquidate(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $club     = $this->clubResolver->resolveRequiredFromRequest($user);
        $detached = $this->liquidation->liquidate($club);
        $window   = $this->configRepository->getConfig()->getPlotDormancyReclaimDays();

        return $this->json([
            'liquidated'      => true,
            'clubId'          => (string) $club->getId(),
            'liquidatedAt'    => $club->getLiquidatedAt()?->format(\DateTimeInterface::ATOM),
            'deleteAfter'     => $club->getDeleteAfter()?->format(\DateTimeInterface::ATOM),
            'detachedClaimIds' => array_map(static fn (PlotClaim $c): string => (string) $c->getId(), $detached),
            'claims'          => array_map(
                static fn (PlotClaim $c): array => PlotClaimController::present($c, $window),
                $this->claimRepository->findActiveForUser($user),
            ),
        ]);
    }
}

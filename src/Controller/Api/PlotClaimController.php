<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Location\PlotClaim;
use App\Entity\User;
use App\Exception\Location\PlotUnavailableException;
use App\Repository\Location\PlotClaimRepository;
use App\Service\Location\PlotClaimService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Buying the land a hold is on, and building a ground on it.
 *
 * IS_AUTHENTICATED_FULLY, not ROLE_CLUB — see PlotHoldController for why that distinction is
 * load-bearing rather than cosmetic.
 *
 * Money comes from ACCOUNT credit (UserLedger), never from club.balance: the device owns a club's
 * balance and overwrites it absolutely on every sync, so a server-side deduction there would
 * survive until the next sync and then vanish. The 402 below therefore reports the LEDGER
 * balance, which is the only durable figure.
 */
#[Route('/api/plots')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class PlotClaimController extends AbstractController
{
    public function __construct(
        private readonly PlotClaimService    $claimService,
        private readonly PlotClaimRepository $claimRepository,
    ) {}

    /** Every plot the caller owns. */
    #[Route('/claims/mine', name: 'api_plot_claims_mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        $claims = $this->claimRepository->findActiveForUser($this->currentUser());

        $response = new JsonResponse([
            'claims' => array_map(fn (PlotClaim $c): array => $this->serialise($c), $claims),
        ]);
        $response->setPrivate();
        $response->setMaxAge(0);

        return $response;
    }

    /**
     * Claim the plot a hold covers, at the price the hold quoted.
     *
     * The hold id comes in the body rather than being inferred from the account: a player can
     * only have one live hold, but making the client name it means a stale client cannot
     * accidentally spend a hold the player took on a different plot since.
     */
    #[Route('/{plotId}/claim', name: 'api_plot_claim_create', methods: ['POST'])]
    public function claim(string $plotId, Request $request): JsonResponse
    {
        $holdId = $request->request->get('holdId') ?? $this->jsonField($request, 'holdId');

        if (!is_string($holdId) || $holdId === '') {
            return $this->json(
                ['error' => 'hold_required', 'message' => 'Take a hold on the plot before claiming it.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $claim = $this->claimService->claimFromHold($this->currentUser(), $holdId);
        } catch (PlotUnavailableException $e) {
            return $this->errorResponse($e);
        }

        if ((string) $claim->getPlot()->getId() !== $plotId) {
            // The hold is for a different plot than the URL names. Refused rather than honoured,
            // because the URL is what the player saw.
            return $this->json(
                ['error' => 'hold_plot_mismatch', 'message' => 'That hold is for a different plot.'],
                Response::HTTP_CONFLICT,
            );
        }

        return $this->json(['claim' => $this->serialise($claim)], Response::HTTP_CREATED);
    }

    /** Build the ground on a plot already owned. */
    #[Route('/claims/{claimId}/stadium', name: 'api_plot_claim_stadium', methods: ['POST'])]
    public function buildStadium(string $claimId): JsonResponse
    {
        $claim = $this->claimRepository->find($claimId);

        if (!$claim instanceof PlotClaim) {
            return $this->json(['error' => 'claim_not_found'], Response::HTTP_NOT_FOUND);
        }

        if ((string) $claim->getUser()->getId() !== (string) $this->currentUser()->getId()) {
            return $this->json(['error' => 'claim_not_yours'], Response::HTTP_FORBIDDEN);
        }

        if (!$claim->isActive()) {
            return $this->json(['error' => 'claim_released'], Response::HTTP_CONFLICT);
        }

        try {
            $claim = $this->claimService->buildStadium($claim);
        } catch (PlotUnavailableException $e) {
            return $this->errorResponse($e);
        }

        return $this->json(['claim' => $this->serialise($claim)]);
    }

    /** @return array<string, mixed> */
    private function serialise(PlotClaim $claim): array
    {
        $location = $claim->getLocation();

        return [
            'claimId'          => (string) $claim->getId(),
            'plotId'           => (string) $claim->getPlot()->getId(),
            'locationId'       => (string) $location->getId(),
            'town'             => $location->getTown(),
            'region'           => $location->getRegion(),
            'countryCode'      => $location->getCountryCode(),
            'claimedBy'        => $claim->getClaimedByName(),
            'clubId'           => $claim->getClub() === null ? null : (string) $claim->getClub()->getId(),
            'claimedAt'        => $claim->getClaimedAt()->format(\DateTimeInterface::ATOM),
            // What was actually paid, never recomputed — land gets revalued and a sell-back
            // needs both the original and the current figure.
            'landPencePaid'    => $claim->getLandPencePaid(),
            'stadiumPencePaid' => $claim->getStadiumPencePaid(),
            'totalPaidPence'   => $claim->getTotalPaidPence(),
            'hasStadium'       => $claim->hasStadium(),
        ];
    }

    private function errorResponse(PlotUnavailableException $e): JsonResponse
    {
        $status = match ($e->reason) {
            PlotUnavailableException::INSUFFICIENT   => Response::HTTP_PAYMENT_REQUIRED,
            PlotUnavailableException::HOLD_EXPIRED   => Response::HTTP_GONE,
            PlotUnavailableException::HOLD_NOT_YOURS => Response::HTTP_FORBIDDEN,
            PlotUnavailableException::NOT_SELLABLE   => Response::HTTP_UNPROCESSABLE_ENTITY,
            default                                  => Response::HTTP_CONFLICT,
        };

        return $this->json(
            ['error' => $e->reason, 'message' => $e->getMessage()] + $e->context,
            $status,
        );
    }

    /** Accepts a JSON body as well as a form post, since the client sends JSON. */
    private function jsonField(Request $request, string $field): mixed
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? ($decoded[$field] ?? null) : null;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}

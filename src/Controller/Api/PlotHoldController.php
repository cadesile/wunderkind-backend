<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Location\LandPlot;
use App\Entity\Location\PlotHold;
use App\Entity\User;
use App\Exception\Location\PlotUnavailableException;
use App\Repository\Location\LandPlotRepository;
use App\Service\Location\PlotHoldService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Taking and releasing a fifteen-minute option on a plot.
 *
 * GATED ON IS_AUTHENTICATED_FULLY, NOT ROLE_CLUB, and that is deliberate. Land can be bought
 * before a club exists — the brief's own flow — and `User::getRoles()` appends ROLE_CLUB
 * unconditionally, so a ROLE_CLUB gate would appear to work in testing and break the day that is
 * corrected. That is the /api/club/check lockout recorded in tribal-knowledge.md, verbatim.
 */
#[Route('/api/plots')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class PlotHoldController extends AbstractController
{
    public function __construct(
        private readonly PlotHoldService    $holdService,
        private readonly LandPlotRepository $plotRepository,
    ) {}

    /** The caller's live hold, with a countdown the client can show. */
    #[Route('/holds/mine', name: 'api_plot_holds_mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        $hold = $this->holdService->activeFor($this->currentUser());

        // Private, no-store: a countdown is useless cached, and the answer is per-account.
        $response = new JsonResponse(['hold' => $hold === null ? null : $this->serialise($hold)]);
        $response->setPrivate();
        $response->setMaxAge(0);

        return $response;
    }

    #[Route('/{plotId}/hold', name: 'api_plot_hold_create', methods: ['POST'])]
    public function hold(string $plotId): JsonResponse
    {
        $plot = $this->plotRepository->find($plotId);

        if (!$plot instanceof LandPlot) {
            return $this->json(['error' => 'plot_not_found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $hold = $this->holdService->acquire($this->currentUser(), $plot);
        } catch (PlotUnavailableException $e) {
            return $this->errorResponse($e);
        }

        return $this->json(['hold' => $this->serialise($hold)], Response::HTTP_CREATED);
    }

    #[Route('/holds/{holdId}', name: 'api_plot_hold_release', methods: ['DELETE'])]
    public function release(string $holdId): JsonResponse
    {
        try {
            $released = $this->holdService->release($this->currentUser(), $holdId);
        } catch (PlotUnavailableException $e) {
            return $this->errorResponse($e);
        }

        // Releasing something already gone is not an error — a player pressing cancel twice is
        // ordinary, and the end state is the one they wanted either way.
        return $this->json(['released' => $released]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(PlotHold $hold): array
    {
        $plot     = $hold->getPlot();
        $location = $plot->getLocation();

        return [
            'holdId'           => (string) $hold->getId(),
            'plotId'           => (string) $plot->getId(),
            'locationId'       => (string) $location->getId(),
            'town'             => $location->getTown(),
            'countryCode'      => $location->getCountryCode(),
            // The FROZEN figures, which is the whole point of a hold.
            'landPence'        => $hold->getLandPence(),
            'stadiumPence'     => $hold->getStadiumPence(),
            'totalPence'       => $hold->getTotalPence(),
            'expiresAt'        => $hold->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'secondsRemaining' => $hold->secondsRemaining(),
        ];
    }

    private function errorResponse(PlotUnavailableException $e): JsonResponse
    {
        $status = match ($e->reason) {
            PlotUnavailableException::HOLD_EXPIRED   => Response::HTTP_GONE,
            PlotUnavailableException::HOLD_NOT_YOURS => Response::HTTP_FORBIDDEN,
            PlotUnavailableException::NOT_SELLABLE   => Response::HTTP_UNPROCESSABLE_ENTITY,
            // plot_held / hold_limit / plot_claimed are all "someone got there first" — a
            // conflict, and in the first two cases worth retrying shortly.
            default                                  => Response::HTTP_CONFLICT,
        };

        return $this->json(
            ['error' => $e->reason, 'message' => $e->getMessage()] + $e->context,
            $status,
        );
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            // Unreachable behind IS_AUTHENTICATED_FULLY; asserted rather than assumed.
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}

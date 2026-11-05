<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Club;
use App\Entity\User;
use App\Exception\Setup\SetupActionException;
use App\Service\ClubResolver;
use App\Service\Setup\ClubFacilityBuildService;
use App\Service\Setup\ClubFundingService;
use App\Service\Setup\ClubSetupStateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The money-moving half of new-game setup: building facility sites, and staking the club's
 * opening capital.
 *
 * THE FINANCIAL BOUNDARY THESE TWO ENDPOINTS SIT ON. Setup purchases are charged to the
 * ACCOUNT's UserLedger credit — land, stadium, and now facilities. The stake
 * (POST /fund) is the last of those debits and the handover: after it, the game session's
 * finances are entirely local, nothing here is queried or updated during gameplay, and the only
 * path back to the account balance is a DIVIDEND_DRAW arriving in a sync payload. Neither
 * endpoint writes `club.balance` — the device owns that column and overwrites it absolutely on
 * every sync, so a server-side figure there would vanish at the first one.
 *
 * `ROLE_CLUB` IS NOT AN OWNERSHIP CHECK. It is appended to every account unconditionally
 * (User::getRoles()), so it means "signed in" and nothing more — see docs/api/role-club-403.md.
 * Ownership is the ClubResolver call in each action, which is a real check and answers 403.
 *
 * BOTH WRITES REQUIRE AN EXPLICIT CLUB ID, via `X-Club-Id` or `clubId` in the body, because
 * resolveRequiredFromRequest() never guesses. An account can own several clubs (one per save
 * slot) and a charge landing on the wrong save would be real money spent on the wrong game.
 */
#[Route('/api/club')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ClubSetupController extends AbstractController
{
    public function __construct(
        private readonly ClubResolver             $clubResolver,
        private readonly ClubFacilityBuildService $buildService,
        private readonly ClubFundingService       $fundingService,
        private readonly ClubSetupStateService    $stateService,
    ) {}

    /**
     * Build out one facility site, charged once.
     *
     * Body: `{"levels": {"<facilitySlug>": <targetLevel>, ...}}`, optionally with `clubId`.
     * `{site}` is one of the facility categories — `training`, `medical`, `scouting`,
     * `stadium` — and the slugs must belong to it. The catalogue the client prices against is
     * already published on `GET /api/game-config` as `facilityTemplates`, so there is no
     * separate read endpoint here: `baseCost`, `maxLevel` and `category` all come from there.
     *
     * IDEMPOTENT ON (club, site). The same levels twice returns `200` with `charged: false` and
     * no second debit; different levels after a charge is `409 site_already_built`.
     */
    #[Route('/facilities/{site}', name: 'api_club_facilities_build', methods: ['POST'])]
    public function buildSite(string $site, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $club = $this->club($user, $request);

        $body   = $this->body($request);
        $levels = $body['levels'] ?? null;

        if (!is_array($levels) || $levels === []) {
            return $this->json(
                [
                    'error'   => 'levels_required',
                    'message' => 'Send the target level for each facility at this site, as {"levels": {"slug": level}}.',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $result = $this->buildService->build($user, $club, $site, $levels);
        } catch (SetupActionException $e) {
            return $this->errorResponse($e);
        }

        return $this->json($result);
    }

    /**
     * Stake the club's opening capital — the atomic debit against the account balance.
     *
     * Body: `{"amountPence": <int>}`, optionally with `clubId`. The response carries
     * `stakedPence` for the client to seed its local capital with, the account's remaining
     * `balancePence`, and the half of the running-cost projection the server can actually
     * compute (facility upkeep — it holds no roster, so wages are the client's half from
     * `GET /api/market/data`).
     *
     * IDEMPOTENT ON (club): the same amount twice returns `200 funded: false` with the original
     * figure; a different amount is `409 already_funded`.
     */
    #[Route('/fund', name: 'api_club_fund', methods: ['POST'])]
    public function fund(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $club = $this->club($user, $request);

        $body   = $this->body($request);
        $amount = $body['amountPence'] ?? null;

        if (!is_int($amount) && !(is_string($amount) && ctype_digit($amount))) {
            return $this->json(
                [
                    'error'   => 'amount_required',
                    'message' => 'Send the stake as {"amountPence": <whole pence>}.',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $result = $this->fundingService->fund($user, $club, (int) $amount);
        } catch (SetupActionException $e) {
            return $this->errorResponse($e);
        }

        return $this->json($result);
    }

    /**
     * Where this club is in setup — the resume call after a mid-BUILD kill.
     *
     * Derived entirely from what was billed and built (ledger rows and facility levels), never
     * from progress flags, so it cannot disagree with the money. Deliberately NOT folded into
     * `GET /api/club/check`, which is the cheap pre-club routing call every cold boot makes.
     */
    #[Route('/setup-state', name: 'api_club_setup_state', methods: ['GET'])]
    public function setupState(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $club = $this->club($user, $request);

        $response = $this->json($this->stateService->stateFor($user, $club));
        // Per-account and it moves on every purchase. Must never sit in a shared cache, and
        // must not be re-served to the same client after a charge.
        $response->setPrivate();
        $response->setMaxAge(0);

        return $response;
    }

    /**
     * HTTP status per refusal.
     *
     * 402 for "not enough credit" matches the land economy's own insufficient-credit answer
     * (PlotClaimController), so the client has one meaning for one code across setup. The
     * conflicts are the idempotency refusals, and they carry enough context to render what the
     * player already owns rather than just saying no.
     */
    private function errorResponse(SetupActionException $e): JsonResponse
    {
        $status = match ($e->reason) {
            SetupActionException::INSUFFICIENT_CREDIT   => Response::HTTP_PAYMENT_REQUIRED,
            SetupActionException::UNKNOWN_SITE          => Response::HTTP_NOT_FOUND,
            SetupActionException::SITE_ALREADY_BUILT,
            SetupActionException::ALREADY_FUNDED,
            SetupActionException::ALREADY_ANSWERED,
            SetupActionException::QUIZ_UNAVAILABLE      => Response::HTTP_CONFLICT,
            SetupActionException::UNKNOWN_FACILITY,
            SetupActionException::INVALID_LEVEL,
            SetupActionException::BELOW_FUNDING_MINIMUM => Response::HTTP_UNPROCESSABLE_ENTITY,
            default                                     => Response::HTTP_BAD_REQUEST,
        };

        return $this->json(
            ['error' => $e->reason, 'message' => $e->getMessage()] + $e->context,
            $status,
        );
    }

    /**
     * The club this request is for.
     *
     * resolveRequiredFromRequest() rather than resolveFromRequest(): it refuses to guess, and
     * its ClubMismatchException/ClubIdRequiredException are HttpExceptions that Symfony turns
     * into 403/400 on their own.
     */
    private function club(User $user, Request $request): Club
    {
        $bodyClubId = $this->body($request)['clubId'] ?? null;

        return $this->clubResolver->resolveRequiredFromRequest(
            $user,
            is_string($bodyClubId) ? $bodyClubId : null,
        );
    }

    /**
     * The JSON body, or an empty array.
     *
     * Decoded on demand rather than via #[MapRequestPayload] because `levels` is an open map of
     * facility slugs — there is no DTO shape for it that would not have to be rewritten every
     * time an admin adds a facility.
     *
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
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

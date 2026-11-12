<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Club;
use App\Entity\User;
use App\Exception\Setup\SetupActionException;
use App\Service\ClubResolver;
use App\Service\Economy\SeasonInvestmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * `GET`/`POST /api/club/season-investment` — the owner gifting their own money into a club.
 *
 * SEPARATE FROM ClubSetupController ON PURPOSE. That controller is the one-time setup flow
 * (build the sites, stake the opening float), and its own docblock describes a three-phase
 * financial life in which setup is the only debit. This is a RECURRING debit during gameplay,
 * so folding it in would make that docblock wrong about the thing it exists to explain.
 *
 * It reuses `SetupActionException` deliberately, though: a client should not need a second error
 * shape for the second way money leaves an account. The status mapping below therefore agrees
 * with `ClubSetupController::errorResponse()` on the one reason both can produce —
 * `insufficient_credit` is 402 in both, matching the land economy's own answer in
 * `PlotClaimController`.
 *
 * ── TWO NEW CLIENT-VISIBLE ERROR CODES ────────────────────────────────────────────────────
 *
 *   409 already_invested_this_season   a different amount for a season already invested in
 *   422 above_season_ceiling           over the resolved per-tier ceiling
 *
 * An older build will not recognise either, which `api-backwards-compatibility.md` counts as a
 * client-visible change — flagged in `docs/api/season-investment.md` rather than assumed to
 * degrade. Both are **unreachable while the ceiling is 0**, which is the shipped state: a base
 * figure of zero makes every amount over the ceiling, so the endpoint refuses everything until
 * an operator authors one. Same shape as `429 enrichment_quota_exhausted` shipping ahead of any
 * client that understood it.
 *
 * ── THE RESPONSE TELLS THE DEVICE TO CREDIT THE CLUB ──────────────────────────────────────
 *
 * `creditClubPence` is an INSTRUCTION, not a report. The server debits the owner's ledger and
 * cannot credit `club.balance` — `SyncService::process()` overwrites that column absolutely
 * from the device on every sync, so a server-side credit would vanish at the next sync with no
 * authority left to repair it from.
 */
#[Route('/api/club')]
#[IsGranted('ROLE_CLUB')]
class SeasonInvestmentController extends AbstractController
{
    public function __construct(
        private readonly ClubResolver            $clubResolver,
        private readonly SeasonInvestmentService $investmentService,
    ) {}

    /**
     * What the owner may invest this season, and whether they already have.
     *
     * Read-only, and it returns the same shape the POST does so a client can render the screen
     * from either without a second code path. `creditClubPence` is 0 here — nothing happened.
     */
    #[Route('/season-investment', name: 'api_club_season_investment_state', methods: ['GET'])]
    public function state(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        return $this->json($this->investmentService->state($user, $this->club($user, $request)));
    }

    /**
     * Invest `{"amountPence": <whole pence>}` for this season.
     *
     * IDEMPOTENT ON (club, season). The same amount twice returns `200 invested: false` with the
     * original figure and no second debit; a different amount is `409
     * already_invested_this_season`.
     *
     * THE SEASON IS NOT A PARAMETER AND CANNOT BE ONE. It comes from the count of this club's
     * `SeasonRecord` rows — a figure only `concludeSeason()` advances — because
     * `club.currentSeason` is device-mirrored and a claimed season would both raise the ceiling
     * (it grows with the inflation index) and unlock windows the club never played.
     */
    #[Route('/season-investment', name: 'api_club_season_investment', methods: ['POST'])]
    public function invest(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $club = $this->club($user, $request);

        $body   = $this->body($request);
        $amount = $body['amountPence'] ?? null;

        // Same guard shape as POST /api/club/fund: a digit string is accepted because JSON
        // clients differ on whether a large integer survives as an int, and a silent (int)
        // cast of a float or a word would turn a malformed request into a real debit.
        if (!is_int($amount) && !(is_string($amount) && ctype_digit($amount))) {
            return $this->json(
                [
                    'error'   => 'amount_required',
                    'message' => 'Send the gift as {"amountPence": <whole pence>}.',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $result = $this->investmentService->invest($user, $club, (int) $amount);
        } catch (SetupActionException $e) {
            return $this->errorResponse($e);
        }

        return $this->json($result);
    }

    // ── Plumbing ──────────────────────────────────────────────────────────

    /**
     * HTTP status per refusal.
     *
     * AGREES WITH ClubSetupController::errorResponse() ON `insufficient_credit` (402), which is
     * the only reason both controllers can produce — one meaning for one code across every way
     * money leaves an account, including PlotClaimController's.
     *
     * The conflict and the ceiling both carry enough context to render what the player already
     * gave or what they CAN give, rather than just saying no. That matters more here than
     * elsewhere: the ceiling is `base x countryFactor x tierFactor x index(season)` and the app
     * is deliberately never told a country factor, so it cannot work the figure out itself.
     */
    private function errorResponse(SetupActionException $e): JsonResponse
    {
        $status = match ($e->reason) {
            SetupActionException::INSUFFICIENT_CREDIT           => Response::HTTP_PAYMENT_REQUIRED,
            SetupActionException::ALREADY_INVESTED_THIS_SEASON  => Response::HTTP_CONFLICT,
            SetupActionException::ABOVE_SEASON_CEILING          => Response::HTTP_UNPROCESSABLE_ENTITY,
            default                                            => Response::HTTP_BAD_REQUEST,
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
            // Unreachable behind ROLE_CLUB, but the resolver's signature is not nullable and a
            // silent null would become a confusing failure deeper in.
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * The club this request is about.
     *
     * Through ClubResolver so `X-Club-Id` naming someone else's club is a 403 rather than a
     * write onto the wrong save — the same guard every other club-scoped endpoint uses. An
     * optional `clubId` in the body is accepted as the fallback, matching
     * ClubSetupController.
     */
    private function club(User $user, Request $request): Club
    {
        $body   = $this->body($request);
        $clubId = $body['clubId'] ?? null;

        return $this->clubResolver->resolveRequiredFromRequest(
            $user,
            is_string($clubId) && Uuid::isValid($clubId) ? $clubId : null,
        );
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent() ?: '{}', true);

        return is_array($decoded) ? $decoded : [];
    }
}

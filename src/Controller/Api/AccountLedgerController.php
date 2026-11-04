<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\UserLedger;
use App\Repository\UserLedgerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The account's credit balance and its history — what the land step spends from.
 *
 * WHY THIS EXISTS AT ALL. Land and stadiums are charged to the ACCOUNT's UserLedger credit,
 * never to club.balance (SyncService::process() assigns club.balance absolutely from the
 * device, so a server-side deduction there survives until the next sync and then vanishes).
 * That made a read endpoint unavoidable the moment plots became purchasable: there was no
 * way for a client to show what it was about to spend. docs/api/land-plots.md already
 * instructed clients to read the balance from here before this controller existed.
 *
 * THE RESPONSE IS ENTITY-SHAPED, NOT SYNC-SHAPED, and the difference is a real trap. The
 * INBOUND sync contract (LedgerEntrySyncDto) uses `category` / `amount`; this endpoint
 * returns `type` / `amountPence`. Three reasons it is spelled this way and must not be
 * "harmonised" with the DTO:
 *
 *   1. docs/api/user-ledger.md's addendum already promised clients `type`, `amountPence` and
 *      a nullable `club`.
 *   2. `...Pence` is the convention for every endpoint built in this era (PlotHoldController,
 *      PlotClaimController, TelemetryController, ExcursionController).
 *   3. A bare `amount` key already means a row COUNT elsewhere in this API
 *      (PlayerController::index()), so reusing it for money would be actively misleading.
 *
 * Note also the units divergence documented in docs/api/user-ledger.md: inbound sync sends
 * every category EXCEPT dividend_draw at 100x true pence. Nothing is rescaled here — this
 * endpoint reports the stored figure, which is what the balance is actually computed from.
 *
 * NEVER CACHED. It is per-user money, and a cached balance shown against a live plot price is
 * how a player ends up seeing "affordable" for something they cannot buy.
 *
 * No access_control entry: the existing `^/api` catch-all in security.yaml already requires a
 * fully authenticated user, and a redundant line is noise. Gated on IS_AUTHENTICATED_FULLY
 * rather than ROLE_CLUB on purpose — credit exists before a club does, and a ROLE_CLUB gate
 * would appear to work in testing because User::getRoles() appends it unconditionally.
 */
#[Route('/api/account')]
class AccountLedgerController extends AbstractController
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    #[Route('/ledger', name: 'api_account_ledger', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function ledger(Request $request, UserLedgerRepository $ledgerRepository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $limit = (int) $request->query->get('limit', (string) self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $cursor = $request->query->get('cursor');

        if ($cursor !== null && $cursor !== '') {
            $decoded = $this->decodeCursor((string) $cursor);

            if ($decoded === null) {
                return $this->json([
                    'error'   => 'invalid_cursor',
                    'message' => 'The cursor is not one this endpoint issued.',
                ], Response::HTTP_BAD_REQUEST);
            }

            [$afterRecordedAt, $afterId] = $decoded;
        } else {
            $afterRecordedAt = null;
            $afterId         = null;
        }

        // limit + 1 rows come back, so "there is another page" needs no COUNT query.
        $rows    = $ledgerRepository->findPageForUser($user, $limit, $afterRecordedAt, $afterId);
        $hasMore = count($rows) > $limit;
        $page    = $hasMore ? array_slice($rows, 0, $limit) : $rows;

        $last = $page !== [] ? $page[count($page) - 1] : null;

        return $this->json([
            // The chain head, i.e. the balanceAfterPence of whichever entry sorts first in this
            // same ordering — so this figure and $page[0]['balanceAfterPence'] always agree.
            'balancePence' => $ledgerRepository->getCurrentBalance($user),
            'entries'      => array_map($this->present(...), $page),
            'nextCursor'   => $hasMore && $last !== null ? $this->encodeCursor($last) : null,
        ])
            // Per-user money: no shared cache, no browser cache, no revalidation window.
            ->setPrivate()
            ->setMaxAge(0);
    }

    /** @return array<string, mixed> */
    private function present(UserLedger $entry): array
    {
        $club = $entry->getClub();

        return [
            'id'                => (string) $entry->getId(),
            'type'              => $entry->getType()->value,
            'label'             => $entry->getType()->label(),
            // SIGNED. Purchases are negative; see UserLedgerEntryType's sign convention.
            'amountPence'       => $entry->getAmountPence(),
            'balanceAfterPence' => $entry->getBalanceAfterPence(),
            // NULL on account-level entries — a grant, or a plot bought before founding a club.
            // Also null once the club it referred to has been deleted, which is why
            // club_name_at_claim is frozen on plot_claim rather than read back through here.
            'club'              => $club !== null
                ? ['id' => (string) $club->getId(), 'name' => $club->getName()]
                : null,
            'description'       => $entry->getDescription(),
            'occurredAt'        => $entry->getOccurredAt()->format(\DateTimeInterface::ATOM),
            'recordedAt'        => $entry->getRecordedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Opaque cursor naming the last row of a page: its recordedAt to the microsecond, plus its
     * id as the tie-break — the exact pair findPageForUser() orders on.
     *
     * Base64url-encoded to make it clear it is not a client-composable value. Microseconds are
     * not optional: two entries written in the same sync share a recordedAt to the second, and a
     * cursor that rounded would either repeat or skip them.
     */
    private function encodeCursor(UserLedger $entry): string
    {
        return rtrim(strtr(base64_encode(
            $entry->getRecordedAt()->format('Y-m-d\TH:i:s.u') . '|' . $entry->getId(),
        ), '+/', '-_'), '=');
    }

    /** @return array{0: \DateTimeImmutable, 1: string}|null null when the cursor is unusable */
    private function decodeCursor(string $cursor): ?array
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($raw === false || !str_contains($raw, '|')) {
            return null;
        }

        [$at, $id] = explode('|', $raw, 2);

        $recordedAt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u', $at);

        // A malformed cursor is a 400, never a silently-ignored one. Silently restarting from
        // the head would make a paging client loop over page 1 for ever with no error to see.
        if ($recordedAt === false || !\Symfony\Component\Uid\Uuid::isValid($id)) {
            return null;
        }

        return [$recordedAt, $id];
    }
}

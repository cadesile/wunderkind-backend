<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\LiveTelemetrySnapshot;
use App\Repository\LiveTelemetrySnapshotRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated — the global "live telemetry" aggregate (24h macro counters
 * plus the merged boardroom incident feed) that the landing page already renders
 * server-side, exposed as JSON so the React Native app can show the same thing.
 *
 * Reads the cached `LiveTelemetrySnapshot` singleton and NEVER aggregates per request:
 * the numbers are produced by `app:telemetry:generate` on a 15-minute cron
 * (`docker/telemetry-generate.sh`), which walks every recent SyncRecord payload and is
 * far too heavy for a request path. A client polling faster than that cadence just
 * re-reads the same row, which is why this sends `Last-Modified`/`ETag` off
 * `generatedAt` and answers a conditional request with 304.
 *
 * Deliberately NOT a verbatim dump of the stored snapshot — see serializeFeedItem() for
 * the three keys that are dropped and why. Full client contract:
 * `docs/api/live-telemetry.md`.
 */
#[Route('/api/telemetry', name: 'api_telemetry', methods: ['GET'])]
class TelemetryController extends AbstractController
{
    /**
     * Mirrors LiveTelemetryService::WINDOW_HOURS / ::EVENTS_WINDOW_DAYS, which are
     * private. Emitted so the client can label its own HUD ("24H PYRAMID FEED") and,
     * more importantly, so `stats.tableTurnover`'s wider window is discoverable from
     * the payload rather than only from this repo's source — see below.
     */
    private const STATS_WINDOW_HOURS = 24;
    private const FEED_WINDOW_DAYS   = 7;

    /**
     * How old a snapshot may be before the page should say so.
     *
     * Two full generation cycles (the cron runs every 15 minutes). One cycle would flag
     * any run that merely started late; two means the cron has actually missed a turn.
     * Computed here rather than client-side because only the server knows the cadence —
     * a client comparing `generatedAt` against its own clock would also be measuring its
     * own clock skew. Not hypothetical: this page served a snapshot frozen for 90 minutes
     * in October while the generator was running out of memory, and nothing in the
     * payload said so.
     */
    private const STALE_AFTER_SECONDS = 1800;

    public function __construct(
        private readonly LiveTelemetrySnapshotRepository $snapshotRepository,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        // getSnapshot() constructs (and persists, without flushing) a zeroed row when the
        // cron has never run. Left unflushed on purpose: a GET must not write. A zeroed
        // snapshot is a legitimate response, not an error — every counter reads 0 and
        // `feed` is empty.
        $snapshot = $this->snapshotRepository->getSnapshot();

        // A snapshot with no id has never been written: getSnapshot() builds a zeroed row
        // in memory and deliberately does not flush it (a GET must not write). That is a
        // different state from "generated, and everything was zero", and the page has to
        // tell them apart — "NOT YET GENERATED" against a world that is merely quiet.
        $generated  = $snapshot->getId() !== null;
        $ageSeconds = time() - $snapshot->getGeneratedAt()->getTimestamp();

        $response = new JsonResponse([
            'generatedAt' => $snapshot->getGeneratedAt()->format(\DateTimeInterface::ATOM),
            // Explicit zero-state flag. False means every counter below reads 0 and `feed`
            // is empty because nothing has ever run, not because nothing happened.
            'generated'   => $generated,
            // Server-computed so the client needs no knowledge of the cron cadence. Stays
            // false in the zero state rather than reporting a freshly-built empty row as
            // delayed: `generated: false` is the stronger signal and takes precedence.
            'stale'       => $generated && $ageSeconds > self::STALE_AFTER_SECONDS,
            'ageSeconds'  => $generated ? max(0, $ageSeconds) : null,
            'windows'     => [
                'statsHours' => self::STATS_WINDOW_HOURS,
                'feedDays'   => self::FEED_WINDOW_DAYS,
            ],
            'stats' => [
                'fixturesSimulated' => $snapshot->getFixturesSimulated(),
                // Real pence. Already corrected for the sync ledger's 100x-inflated
                // `amount` convention by LiveTelemetryService::ledgerAmountToPence() at
                // generation time, so it needs no further scaling here or client-side.
                // Cast because the column is a Doctrine `bigint`, which hydrates as a
                // PHP string — without this the field's JSON type would differ from
                // every other counter.
                'capitalDeployedPence' => (int) $snapshot->getCapitalDeployedPence(),
                'results'              => [
                    'wins'   => $snapshot->getResultsWins(),
                    'draws'  => $snapshot->getResultsDraws(),
                    'losses' => $snapshot->getResultsLosses(),
                ],
                'activeClubs' => $snapshot->getActiveClubs(),
                // sync count * WEEKS_PER_SYNC (4) — an approximation of on-device weekly
                // ticks, not a true weeks-elapsed count. Flagged in-band so a client
                // can't present it as exact just because it read the field name.
                'weeksPlayed'            => $snapshot->getWeeksPlayed(),
                'weeksPlayedApproximate' => true,
                'totalGoals'             => $snapshot->getTotalGoals(),
                'totalMatches'           => $snapshot->getTotalMatches(),
                // Counted over the 7-day FEED window, not the 24h stats window the rest
                // of this block uses: a promotion/relegation only lands when a season
                // concludes, which is far rarer than a sync, so a 24h count would read 0
                // almost permanently. Deliberate; do not "fix" by narrowing it.
                'tableTurnover'         => $snapshot->getTableTurnover(),
                'tableTurnoverWindowDays' => self::FEED_WINDOW_DAYS,
                // Nullable by design: null means "not enough paired morale readings to
                // compute a delta", which is different from 0 ("no net change").
                'communityMoraleDelta' => $snapshot->getCommunityMoraleDelta(),
            ],
            'feed' => array_values(array_map(
                static fn (array $event): array => self::serializeFeedItem($event),
                $snapshot->getRecentEvents(),
            )),
        ]);

        $response->setLastModified($snapshot->getGeneratedAt());
        $response->setEtag(md5((string) $snapshot->getGeneratedAt()->getTimestamp()));
        $response->setPublic();
        // Half the 15-minute generation cadence: long enough that polling clients and any
        // CDN mostly hit cache, short enough that a fresh snapshot surfaces within a cycle.
        $response->setMaxAge(450);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Projects one stored feed item onto the client shape. Three keys of the stored
     * event are deliberately NOT emitted:
     *
     * - `at` — a real \DateTimeImmutable that LiveTelemetryService persists into the
     *   `recent_events` json column (it sorts on it and never unsets it). Through
     *   json_encode that becomes `{"date":…,"timezone_type":3,"timezone":…}`, a PHP
     *   serialization artifact with no business meaning. `timestampIso` is the same
     *   instant in a portable form, so that is what ships.
     * - `time` — a relative string ("3 hours ago") rendered at GENERATION time, so it is
     *   already up to 15 minutes stale when served and staler still if the cron lags.
     *   A client that cached the response would show a frozen age. Compute relative time
     *   from `timestampIso` instead.
     * - `dossier` — the per-club match/player bundle attachClubDossiers() bakes in for
     *   the web boardroom overlay. Excluded by product decision for this endpoint; it is
     *   the bulk of an event's bytes (up to 5 fixtures + a player likeness each, across
     *   20 events) and the app has no screen for it.
     *
     * Everything else passes through unchanged, so a new field appearing in the
     * generator does NOT silently vanish here — but note the pass-through is explicit
     * rather than a blind spread, so a newly-added key needs a line in this method and
     * a line in the contract doc.
     */
    private static function serializeFeedItem(array $event): array
    {
        return [
            // The machine-readable category: switch on THIS. One of PYRAMID, OUTLAY,
            // ATTENDANCE, DRESSING_ROOM, DILUTION, COVENANT, TALISMAN, COMMUNITY,
            // TOP_TRANSFER, TOP_SCORER — and treat the list as open, since a new
            // category can ship server-side without an app release.
            'category' => $event['category'] ?? null,
            // Terminal-styled chrome for the landing page, e.g. "[PYRAMID // MOVEMENT]".
            // Kept because it is data, but it is a web presentation string — an app
            // should render its own label off `category`, not show these brackets.
            'categoryLabel' => $event['categoryLabel'] ?? null,
            'text'          => $event['text'] ?? null,
            'detail'        => $event['detail'] ?? null,
            // Pre-formatted GBP display string (e.g. "£246,300") built by
            // LiveTelemetrySnapshot::formatPenceExact(), with the £ and grouping
            // hardcoded — it cannot be localized or re-formatted client-side, and the
            // raw pence is not stored on the event. Null where a figure could not be
            // established (the generator shows nothing rather than guessing).
            'amountExact' => $event['amountExact'] ?? null,
            // Display name of the club this item is about. NOT an identifier: club names
            // are not unique (production carries six "London FC"), so key on `clubId`.
            'club' => $event['club'] ?? null,
            // The real Club row behind the item, when there is one. Null for items whose
            // club is a name only — a transfer's destination may be an NPC side with no
            // Club row at all.
            'clubId' => $event['clubId'] ?? null,
            // The club's kit/badge config, same KitConfig shape the app's KitSprite
            // already renders. Nullable.
            'clubBadge' => $event['clubBadge'] ?? null,
            // Every club the line names, in order, each with a badge where a real Club row
            // exists. Entry 0 is the primary club. Only it can carry a badge — the others
            // are free text out of the sync payload (a transfer's other side is often an
            // NPC with no row), so a null badge here means "no row", not "no kit set".
            // `club`/`clubId`/`clubBadge` above stay for callers that only want the first.
            'clubs' => array_values($event['clubs'] ?? []),
            // Free-form per-category extras; shape varies by `category`.
            'meta'         => $event['meta'] ?? [],
            'timestampIso' => $event['timestampIso'] ?? null,
        ];
    }
}

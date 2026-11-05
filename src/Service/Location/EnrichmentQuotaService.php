<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\User;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * How many AI land valuations an account may ask for, and how many it has spent.
 *
 * WHY A QUOTA EXISTS AT ALL. `POST /api/locations/{id}/enrich` is the only thing a player can do
 * that spends real money: one billed external API call per town. The catalogue is ~4,200 towns
 * and climbing, so without a ceiling one authenticated account can commit the whole enrichment
 * budget by walking it — not even maliciously, just by browsing hard.
 *
 * THE METER COUNTS DISPATCHES, NOT REQUESTS. A request that finds the town already queued bills
 * nothing: a double tap, a retry after a timeout, or a second player opening a popular town all
 * resolve to the same in-flight job. Charging quota for those would take an account's budget for
 * calls that never happened, so `record()` is called only by the caller that actually won the
 * claim. The counter therefore tracks spend, which is what the ceiling is about.
 *
 * LIFETIME TOTAL, NOT A ROLLING WINDOW. The requirement is a cap on paid calls per account, and
 * a monotonic counter satisfies it with no scheduled job and no table of timestamps. A per-hour
 * allowance is a different feature; it would need the timestamps and should not be grafted onto
 * this counter. Said plainly here so nobody reads the absence as an oversight.
 *
 * ZERO MEANS UNLIMITED. See StarterConfig::$maxEnrichmentRequestsPerUser — the column lands on
 * databases where enrichment is already deployed and working, and a default meaning "nobody may
 * enrich" would break a live endpoint the moment the migration ran.
 */
final class EnrichmentQuotaService
{
    public function __construct(
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly EntityManagerInterface  $em,
    ) {}

    /**
     * The account's standing, shaped for the API.
     *
     * `remaining` is null rather than a large number when unlimited, because a client that does
     * arithmetic on a sentinel like PHP_INT_MAX will eventually render it.
     *
     * @return array{used: int, max: int, remaining: ?int, unlimited: bool, exhausted: bool}
     */
    /**
     * SERVES TWO SETS OF FIELD NAMES FOR ONE FACT, DELIBERATELY.
     *
     * `used`/`max`/`remaining`/`unlimited`/`exhausted` is this endpoint's own shape and what
     * `docs/api/locations.md` documents. `enrichmentCount`/`enrichmentCountMax` are ALIASES the
     * shipped client reads — its allowance() bails out when `enrichmentCount` is null, which
     * meant the VALUATIONS LEFT strip never rendered and VALUE TOWN stayed permanently enabled
     * against the documented shape alone. The backend ships ahead of the app, so both are sent
     * rather than the app being asked to catch up before the strip works.
     *
     * **`enrichmentCountMax` IS `null` WHEN THE ALLOWANCE IS UNLIMITED**, which is the one thing
     * the alias cannot express in the client's own terms: the ceiling is stored as `0` meaning
     * unlimited, and passing that straight through would read as "none allowed" and disable the
     * button for everyone. There are two ways to misread the null, and the shipped drop hits the
     * second: treating it as zero disables the button, and `?? DEFAULT_ALLOWANCE` inverts
     * unlimited into the smallest cap the client knows.
     *
     * Dev now carries a real ceiling (25, set 2026-11-05), so the null is not the everyday path
     * there any more — but it stays one admin field away, and a fresh environment starts at 0
     * because the migration sets it that way deliberately. See docs/api/locations.md.
     *
     * NOTE: these aliases are ALSO repeated at the top level of the two
     * `POST /api/locations/{id}/enrich` responses, where this array is nested under `quota` —
     * see LocationController::quotaPayload(). Here they are already top-level, because this
     * array is the whole body of GET /api/account/enrichment-quota.
     */
    public function quotaFor(User $user): array
    {
        $max  = $this->starterConfigRepository->getConfig()->getMaxEnrichmentRequestsPerUser();
        $used = $user->getEnrichmentCount();

        if ($max === 0) {
            return [
                'used'      => $used,
                'max'       => 0,
                'remaining' => null,
                'unlimited' => true,
                'exhausted' => false,
                // ALIASES — see the method docblock. `null` is the honest value for an
                // unlimited ceiling: 0 here would render as "0 valuations left" and disable the
                // button on an account that may actually ask for any number.
                'enrichmentCount'    => $used,
                'enrichmentCountMax' => null,
            ];
        }

        return [
            'used'      => $used,
            'max'       => $max,
            'remaining' => max(0, $max - $used),
            'unlimited' => false,
            'exhausted' => $used >= $max,
            'enrichmentCount'    => $used,
            'enrichmentCountMax' => $max,
        ];
    }

    /**
     * Whether this account has spent its allowance.
     *
     * Uses `>=` rather than `===` on purpose: an allowance LOWERED by an admin leaves accounts
     * already past the new ceiling, and those must be refused rather than wrap around into
     * having budget again.
     */
    public function isExhausted(User $user): bool
    {
        return $this->quotaFor($user)['exhausted'];
    }

    /**
     * Charge the account for one valuation.
     *
     * Call this ONLY after a dispatch actually happened. Flushes immediately so the charge is
     * durable before the response goes out — a player who is told "queued" must not be able to
     * reload their way back to a full allowance.
     */
    public function record(User $user): void
    {
        $user->recordEnrichmentRequest();
        $this->em->flush();
    }
}

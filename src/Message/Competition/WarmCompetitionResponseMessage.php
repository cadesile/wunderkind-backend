<?php

declare(strict_types=1);

namespace App\Message\Competition;

/**
 * Rebuild one competition's prebuilt bracket response.
 *
 * **This exists as a separate message rather than a tail call, and that is the whole point.**
 * Prewarming used to happen inline at the end of a draw or resolve cron tick, where it was
 * wrong twice over: the cache write went through the shared EntityManager, so a failure
 * closed it and stranded the next round of the tick; and the document was built from
 * entrants the tick had already loaded, which Doctrine does not re-hydrate — so a
 * resubmission committing mid-tick was counted in the fingerprint and absent from the
 * document, storing a stale bracket under a fresh key. Phase 0 removed the inline prewarm for
 * both reasons.
 *
 * A message fixes the second reason structurally: Symfony's
 * DoctrineClearEntityManagerWorkerSubscriber clears the EntityManager before each message is
 * handled, so the handler starts clean and `sharedDocument()` reads fresh state. The first
 * reason is already gone — the cache row is written through DBAL.
 *
 * Dispatched after a draw or resolve has **committed**, so the fingerprint and document it
 * builds describe the post-work state rather than the state on the way in.
 *
 * Carries only the competition id: the handler recomputes the fingerprint itself, so a
 * duplicate or late delivery just rebuilds the same document, or finds the state has moved
 * on and rebuilds the newer one. Either way it cannot store a mismatched pair.
 */
final class WarmCompetitionResponseMessage
{
    public function __construct(
        public readonly string $competitionId,
    ) {}
}

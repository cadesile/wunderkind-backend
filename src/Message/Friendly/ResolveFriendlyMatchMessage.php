<?php

declare(strict_types=1);

namespace App\Message\Friendly;

/**
 * Resolve one accepted FriendlyChallenge whose kickoffAt has passed.
 *
 * Carries nothing but the challenge id, on purpose — the same shape and reasoning as
 * ResolveCompetitionRoundMessage. The handler re-reads the challenge and takes the claim
 * itself, so this message is a nudge rather than a work order, which is what makes it safe
 * for the dispatcher to send one on every tick without tracking what it has already sent.
 *
 * **The claim deliberately does not happen at dispatch time.** If it did, the lease
 * (FriendlyChallenge::CLAIM_LEASE_SECONDS) would start counting down while the message sat
 * in the queue, so a backlog would silently expire its own claims; and a message lost
 * before delivery would leave a challenge claimed by nobody — with two clubs' stakes
 * escrowed behind it. Claiming inside the handler keeps the claim adjacent to the work it
 * protects, and makes a duplicate delivery free: the second handler's claim simply fails
 * and it returns.
 */
final class ResolveFriendlyMatchMessage
{
    public function __construct(
        public readonly string $challengeId,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Service\MatchEngine;

use App\Enum\Competition\MatchEngineIdentifier;

/**
 * The non-side inputs to resolving one match.
 *
 * Three things used to be smuggled in through a CompetitionFixture entity: the id to seed
 * the RNG from, which engine was asked for (AiEngineStub reads it off
 * `$fixture->getRound()`), and — via a separate `resolveFriendly()` method rather than any
 * parameter — whether a draw is a legal outcome.
 *
 * Naming them has two payoffs. A friendly no longer has to fabricate a fixture, round,
 * competition, template and two entrants to supply them. And "a friendly allows draws"
 * becomes a property of the match rather than a method name, so friendlies can go through
 * MatchEngineInterface and the engine registry like anything else, instead of reaching past
 * both to call DeterministicEngine directly.
 */
final class MatchEngineContext
{
    /**
     * @param string $matchId Identity the result is seeded from: a CompetitionFixture id for a
     *                        cup tie, a FriendlyChallenge id for a friendly. Same id in means
     *                        same result out, which is the whole contract of a seeded engine.
     * @param bool $allowDraws Cup ties must be decisive (extra time, then penalties); friendlies
     *                         may end level.
     * @param MatchEngineIdentifier|null $requestedEngine What the caller asked for, which can
     *                                                    differ from what resolves it — the audit
     *                                                    trail records both.
     */
    public function __construct(
        public readonly string $matchId,
        public readonly bool $allowDraws = false,
        public readonly ?MatchEngineIdentifier $requestedEngine = null,
    ) {}

    public static function forCupTie(string $fixtureId, ?MatchEngineIdentifier $requestedEngine = null): self
    {
        return new self($fixtureId, allowDraws: false, requestedEngine: $requestedEngine);
    }

    public static function forFriendly(string $challengeId): self
    {
        return new self($challengeId, allowDraws: true, requestedEngine: MatchEngineIdentifier::DETERMINISTIC);
    }
}

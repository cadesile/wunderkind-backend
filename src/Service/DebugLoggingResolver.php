<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GameConfig;
use App\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The one place the in-app debug log panel's effective on/off state is decided.
 *
 * Two switches feed it and the direction between them is load-bearing:
 *
 * - `GameConfig::$debugLoggingEnabled` — the global switch, turns the panel on for everyone.
 * - `User::$debugLoggingEnabled` — a per-account override, so one player's device can be
 *   debugged without exposing the panel (it records every API request and response) to the
 *   whole player base.
 *
 * The result is `global || per-user`, never per-user alone: the override may only ever turn
 * the panel ON while the global switch is off, and can never turn it OFF for an account the
 * global switch covers.
 *
 * All-static and dependency-free (same posture as TrophyConfigFactory and
 * CountryContentRegistry) because its callers already hold the `GameConfig` singleton and
 * the current user — injecting a repository here would mean a second fetch of a row the
 * caller is holding.
 *
 * **Two endpoints serve this flag and must not disagree:** `GET /api/game-config` (public —
 * see its own docblock for why an anonymous caller only ever gets the global half) and
 * `GET /api/club/status` (authenticated, so the per-user half always applies there). Both go
 * through here, and GameConfigDebugFlagTest asserts the two agree for the same account. A
 * third consumer should call this too rather than re-deriving `||`.
 */
final class DebugLoggingResolver
{
    public static function resolve(GameConfig $config, ?UserInterface $user): bool
    {
        if ($config->isDebugLoggingEnabled()) {
            return true;
        }

        return $user instanceof User && $user->isDebugLoggingEnabled();
    }
}

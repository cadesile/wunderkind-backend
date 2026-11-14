<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * Turning what an operator typed into a figure in pence.
 *
 * EXTRACTED FROM LeagueAdminController::parseMoneyToPence(), where it was private, once the club
 * buyout overlay needed the same job done. The comments below record two defects that actually
 * shipped on admin money fields, and a second copy of this would be free to lose both of them —
 * silently, because the failure mode in each case is a wrong number stored without an error.
 *
 * TWO RULES, EACH PAID FOR:
 *
 *   - THOUSANDS SEPARATORS SURVIVE. A bare `(int)` cast stops at the first non-digit, so
 *     "1,000" became 1 and stored £10 instead of £1,000 — on a field the admin screen itself
 *     renders WITH separators. The comma strip is not cosmetic.
 *   - DECIMAL POUNDS SURVIVE. The old body was `((int) $digits) * 100`, and the int cast
 *     truncates at the point, so "1000.50" stored £1,000.00 and lost fifty pence. Rounding the
 *     float is what carries the pence half, and the `* 100` happens after it so there is exactly
 *     one rounding step.
 *
 * GARBAGE IS NULL, NOT ZERO, and that is the part callers must handle rather than ignore.
 * `(int) 'abc'` is 0, which reads as a deliberate figure of nothing — a real and different
 * configuration from "no value given". Returning null forces the caller to say which it means:
 * League's fields clear, and a buyout refuses the submission outright, because a payout of £0
 * arrived at by mistyping is not a payout anyone authorised.
 */
final class MoneyInput
{
    /** @return int|null null when the input is blank or not a number — never 0 by accident */
    public static function toPence(?string $raw): ?int
    {
        $digits = str_replace(',', '', trim((string) $raw));

        if ($digits === '') {
            return null;
        }

        if (!is_numeric($digits)) {
            return null;
        }

        return (int) round((float) $digits * 100);
    }
}

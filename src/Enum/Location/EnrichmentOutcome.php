<?php

namespace App\Enum\Location;

/**
 * How one enrichment attempt ended.
 *
 * PARTIAL is the interesting case and the reason this is not a boolean: an LLM asked for a
 * town's coordinates, population and land value can answer two of the three plausibly and the
 * third absurdly. EnrichmentValidator rejects per FIELD, not per response, so the good values
 * are kept and the bad ones are named in the attempt's `rejected_fields` — which is what makes
 * a bad prompt diagnosable instead of just disappointing.
 */
enum EnrichmentOutcome: string
{
    /** Every field the provider returned passed validation. */
    case ACCEPTED = 'accepted';

    /** Some fields were written, others rejected. The location lands NEEDS_REVIEW. */
    case PARTIAL = 'partial';

    /** The provider answered, but nothing it said was usable. */
    case REJECTED = 'rejected';

    /** The provider could not be reached, or threw. Distinct from REJECTED on purpose. */
    case ERROR = 'error';

    public function isWritable(): bool
    {
        return $this === self::ACCEPTED || $this === self::PARTIAL;
    }

    public function label(): string
    {
        return match ($this) {
            self::ACCEPTED => 'Accepted',
            self::PARTIAL  => 'Partial',
            self::REJECTED => 'Rejected',
            self::ERROR    => 'Error',
        };
    }

    public function badgeColour(): string
    {
        return match ($this) {
            self::ACCEPTED => 'success',
            self::PARTIAL  => 'warning',
            self::REJECTED => 'danger',
            self::ERROR    => 'dark',
        };
    }
}

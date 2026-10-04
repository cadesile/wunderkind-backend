<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when a client-supplied country code doesn't resolve via Country::tryFrom() after
 * normalization. ClubInitRequest::$country only enforces a max length (2 chars) — it never
 * validated the value is a real, canonically-cased code, so a mis-cased or garbled code
 * (e.g. "us" instead of "US") used to get persisted onto Club::$country verbatim. That silently
 * broke two things downstream, both of which compare it case-sensitively against Country's
 * UPPERCASE-only values: ClubInitializationService's own duplicate-club-name check
 * (NpcClubRepository::clubNameExists() never matched, so a clashing name silently passed) and
 * StarterPackService's nationality resolution (ClubInitializationService::countryToNationality()
 * returned null, falling back to the raw code as a literal "nationality" string that matches no
 * real player — the pool query for the AMP squad always came up empty for that nationality).
 */
class InvalidCountryCodeException extends \RuntimeException
{
    public function __construct(private readonly string $countryCode)
    {
        parent::__construct(sprintf("Unknown country code '%s'.", $countryCode));
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }
}

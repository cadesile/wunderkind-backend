<?php

declare(strict_types=1);

namespace App\Service\Location\CountryEnrichment;

use App\Entity\Location\CountryProfile;

/**
 * Asks an AI provider for the country-level facts the seeder does not have.
 *
 * SAME CONTRACT AS LocationEnricherInterface, one level up. Implementations must throw rather
 * than return a half-filled answer on a transport or auth failure, must never write to the
 * profile, and must not assume their answer will be used — CountryProfileValidator rejects
 * per field.
 *
 * ONE CALL PER COUNTRY, and that is what makes this feature cheap where town enrichment is
 * not: there are at most as many countries as App\Enum\Country has cases, against 865 towns
 * and growing. It is also why there is no queue — see CountryProfileEnrichmentService.
 */
interface CountryProfileEnricherInterface
{
    /** @throws CountryEnrichmentUnavailableException when the provider cannot be reached or is unconfigured */
    public function enrich(CountryProfile $country): CountryProfileFacts;

    /** Short provider slug, stored on the run this produces. */
    public function provider(): string;

    public function model(): string;

    /** Bumped whenever the prompt text or schema changes, so odd answers can be traced to it. */
    public function promptVersion(): string;

    /**
     * Whether the provider is configured at all — a no-I/O read.
     *
     * NOT A CONNECTION CHECK. The enrichment screen already makes one live round trip per page
     * load and caches it for a minute precisely because an uncached one hung the page for the
     * client's full timeout; a second button must not buy that cost again. The screen's
     * existing verdict is what gates this button, and this is only for callers that have no
     * verdict to hand, such as the command.
     */
    public function isConfigured(): bool;
}

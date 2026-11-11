<?php

declare(strict_types=1);

namespace App\Service\Location\CountryEnrichment;

use App\Entity\Admin;
use App\Entity\Location\CountryProfile;
use App\Entity\Location\LocationEnrichmentRun;
use App\Enum\Location\DistanceUnit;
use App\Enum\Location\EnrichmentTrigger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Ask, validate, write, record — one country, one call, in the caller's process.
 *
 * SYNCHRONOUS, AND THAT IS A DELIBERATE DEPARTURE from everything else in this feature.
 * Town enrichment and the town pull are both queued, and the town pull is queued because it
 * learned the hard way: one call for 300 names was a 60-180s generation behind an nginx that
 * gives up at 60, so the operator saw a failure for work that was often still running.
 *
 * None of that applies to a dozen short scalars. A queue here would buy a transport, a
 * message, a handler, a consumer script and a progress poll, in exchange for making a
 * two-second call feel slower — and it would lose the thing that makes this button worth
 * having, which is that the operator sees which fields were written and which were refused
 * while they are still looking at the country.
 *
 * FILL GAPS ONLY unless told otherwise. See CountryProfileValidator: country_profile has no
 * per-field lock columns, so "a re-run never undoes a human correction" is expressed as
 * skipping a field that already holds a value.
 *
 * ONE RUN ROW, WRITTEN COMPLETE. There is no queue to drain and no later tick to close it,
 * so the run is persisted already settled. See EnrichmentTrigger::ADMIN_COUNTRY_PROFILE for
 * what its counters mean here and why no attempt row is written.
 */
final class CountryProfileEnrichmentService
{
    public function __construct(
        private readonly CountryProfileEnricherInterface $enricher,
        private readonly CountryProfileValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param bool $dryRun ask and validate, but write nothing — neither the profile nor a run
     *
     * @throws CountryEnrichmentUnavailableException when the provider cannot be reached
     */
    public function enrich(
        CountryProfile $country,
        bool $overwrite = false,
        ?Admin $requestedBy = null,
        bool $dryRun = false,
    ): ValidatedCountryProfile {
        $facts   = $this->enricher->enrich($country);
        $verdict = $this->validator->validate($country, $facts, $overwrite);

        if (!$dryRun) {
            $this->apply($country, $verdict);
            $this->recordRun($country, $facts, $verdict, $requestedBy);
            $this->em->flush();
        }

        $this->logger->info('Country profile enrichment', [
            'country'       => $country->getCode(),
            'provider'      => $this->enricher->provider(),
            'model'         => $this->enricher->model(),
            'promptVersion' => $this->enricher->promptVersion(),
            'overwrite'     => $overwrite,
            'dryRun'        => $dryRun,
            'accepted'      => array_keys($verdict->accepted),
            'rejected'      => $verdict->rejected,
            'skipped'       => $verdict->skipped,
            'inputTokens'   => $facts->inputTokens,
            'outputTokens'  => $facts->outputTokens,
            // The provider's own words, kept out of the database but not out of the log: when
            // a band looks odd the question is whether the model said something silly or the
            // validator's bounds are wrong, and nothing else can answer that.
            'raw'           => $facts->raw,
        ]);

        return $verdict;
    }

    /**
     * Write the accepted fields, and nothing else.
     *
     * A field the validator did not accept is not touched at all — not nulled, not defaulted.
     * The country keeps exactly what it had, which is what makes a refused answer harmless.
     */
    private function apply(CountryProfile $country, ValidatedCountryProfile $verdict): void
    {
        foreach ($verdict->accepted as $field => $value) {
            match ($field) {
                'bbox'                  => $country->setBbox($value),
                'landPenceMin'          => $country->setLandPenceMin($value),
                'landPenceMax'          => $country->setLandPenceMax($value),
                'isoAlpha3'             => $country->setIsoAlpha3($value),
                'continent'             => $country->setContinent($value),
                'defaultTimezone'       => $country->setDefaultTimezone($value),
                'currencyCode'          => $country->setCurrencyCode($value),
                'landPriceCurrencyCode' => $country->setLandPriceCurrencyCode($value),
                'distanceUnit'          => $country->setDistanceUnit($value instanceof DistanceUnit ? $value : DistanceUnit::KM),
                // Unreachable while apply() and the validator agree, but a silent no-op here
                // would be a field that validates, reports as written, and never lands.
                default                 => throw new \LogicException(sprintf('No writer for accepted field %s.', $field)),
            };
        }
    }

    private function recordRun(
        CountryProfile $country,
        CountryProfileFacts $facts,
        ValidatedCountryProfile $verdict,
        ?Admin $requestedBy,
    ): void {
        $run = new LocationEnrichmentRun(
            EnrichmentTrigger::ADMIN_COUNTRY_PROFILE,
            $this->enricher->provider(),
            $this->enricher->model(),
            $country->getCode(),
            $requestedBy,
        );

        // `queued` is what the model ANSWERED, not what it was asked: the ask is fixed, and a
        // run that offered nine fields and had two refused is a different story from one that
        // offered two. Counter meanings are documented on the enum case.
        $run->setQueued($this->answeredFieldCount($facts));
        $run->setSettled(
            succeeded: \count($verdict->accepted),
            failed: \count($verdict->rejected),
        );
        $run->markComplete();

        $this->em->persist($run);
    }

    /** How many of the twelve the model actually supplied — a null is a declined field, not an answer. */
    private function answeredFieldCount(CountryProfileFacts $facts): int
    {
        $answered = [
            $facts->bbox,
            $facts->landPenceMin,
            $facts->landPenceMax,
            $facts->isoAlpha3,
            $facts->continent,
            $facts->defaultTimezone,
            $facts->currencyCode,
            $facts->landPriceCurrencyCode,
            $facts->distanceUnit,
        ];

        return \count(array_filter($answered, static fn (mixed $v): bool => $v !== null));
    }
}

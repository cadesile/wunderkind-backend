<?php

declare(strict_types=1);

namespace App\Service\Location\CountryEnrichment;

/**
 * What a provider said about a country. Untrusted, unvalidated, unwritten.
 *
 * Every field is nullable because the schema asks for them required-and-nullable: the model
 * must not be able to silently omit one, but it must be able to decline one honestly. A null
 * here means "not supplied", which CountryProfileValidator treats as nothing to do rather
 * than as a rejection.
 *
 * THE LAND BAND ARRIVES IN PENCE, converted from the pounds the model was asked for. Same
 * reasoning as EnrichmentResult::$landPence: a model asked for pence loses the factor of 100
 * across a wide range, and an answer wrong by 100x sits inside a plausible band for a
 * different country, so it would be accepted rather than caught.
 */
final readonly class CountryProfileFacts
{
    /**
     * @param array{minLat: float, maxLat: float, minLng: float, maxLng: float}|null $bbox
     * @param array<string, mixed>                                                   $raw
     */
    public function __construct(
        public ?array $bbox = null,
        public ?int $landPenceMin = null,
        public ?int $landPenceMax = null,
        public ?string $isoAlpha3 = null,
        public ?string $continent = null,
        public ?string $defaultTimezone = null,
        public ?string $currencyCode = null,
        public ?string $landPriceCurrencyCode = null,
        public ?string $distanceUnit = null,
        public array $raw = [],
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}
}

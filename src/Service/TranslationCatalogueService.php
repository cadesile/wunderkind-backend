<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Language;
use App\Repository\TranslationRepository;

/**
 * Builds the generic UI-copy translation file, in the exact {_meta, entries} shape the
 * frontend's own locale files use (see wunderkind-app/locales/en/translations.json) — this
 * is the public API response shape, and also the admin bulk export/import shape, so the two
 * are always in sync by construction.
 *
 * Narrative content (GameEventTemplate/FacilityTemplate/Excursion) is deliberately excluded
 * from `entries` — it is localized in place on its own API endpoints instead. See
 * NarrativeTranslationService.
 */
class TranslationCatalogueService
{
    /**
     * "2" signals this is the backend-admin-managed catalogue, distinct from the frontend
     * build tool's own "1" — not a real semver. There is no longer a "scan JSX source" step
     * for a sourceCommit field to truthfully describe, so it's omitted entirely rather than
     * faked.
     */
    private const GENERATOR_VERSION = '2';

    public function __construct(
        private readonly TranslationRepository $translationRepository,
    ) {}

    public function buildFullFile(Language $requested, Language $default): array
    {
        $catalogue = $this->translationRepository->getGenericCatalogueForLanguage($requested, $default);

        $entries = $catalogue['entries'];
        ksort($entries);

        return [
            '_meta'   => [
                'generatedAt'         => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'generatorVersion'    => self::GENERATOR_VERSION,
                'entryCount'          => count($entries),
                'versionHash'         => md5(json_encode($entries, JSON_THROW_ON_ERROR)),
                'pluralSensitiveKeys' => $catalogue['pluralSensitiveKeys'],
                // A map, not a list — cast so an empty result serializes as JSON {} rather
                // than [], which a strict client typing this field as a map would reject.
                'bandedReferences'    => (object) $catalogue['bandedReferences'],
            ],
            // Same object-vs-list reasoning as bandedReferences above — entries is a map
            // keyed by translation key, and an empty catalogue must still serialize as {}.
            'entries' => (object) $entries,
        ];
    }

    public function buildVersionHash(Language $requested, Language $default): string
    {
        $entries = $this->translationRepository->getGenericCatalogueForLanguage($requested, $default)['entries'];
        ksort($entries);

        return md5(json_encode($entries, JSON_THROW_ON_ERROR));
    }
}

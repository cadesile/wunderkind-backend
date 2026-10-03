<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Language;
use App\Entity\TranslationKey;
use App\Repository\TranslationKeyRepository;
use App\Repository\TranslationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bulk import/export for the generic UI-copy translation catalogue — a separate domain from
 * NarrativeImportExportService's own translations section (narrative content), matching this
 * codebase's existing precedent of independent *ImportExportService per domain.
 *
 * The export format IS the public /api/translations/{code} response shape (see
 * TranslationCatalogueService) — an admin can upload the frontend's own locale file
 * (wunderkind-app/locales/en/translations.json) close to verbatim as the initial EN seed.
 */
class TranslationCatalogueImportExportService
{
    public function __construct(
        private readonly TranslationCatalogueService $catalogueService,
        private readonly TranslationKeyRepository    $translationKeyRepository,
        private readonly TranslationRepository       $translationRepository,
        private readonly EntityManagerInterface       $em,
    ) {}

    public function export(Language $language, Language $default): array
    {
        return $this->catalogueService->buildFullFile($language, $default);
    }

    /**
     * @return array{created: int, updated: int, errors: string[]}
     */
    public function import(array $data, Language $target): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];

        // A key newly created in the meta step below is only persist()ed, not yet flushed —
        // a later findOneByKey() query for the same key during the entries step would not
        // see it and would create a second, colliding row. This local cache is shared by
        // both steps so each key resolves to the same in-memory entity regardless of order.
        $cache = [];

        $meta = $data['_meta'] ?? [];
        foreach ((array) ($meta['pluralSensitiveKeys'] ?? []) as $keyName) {
            $this->ensureGenericKey((string) $keyName, $cache)->setIsPluralSensitive(true);
        }
        foreach ((array) ($meta['bandedReferences'] ?? []) as $keyName => $fragments) {
            $this->ensureGenericKey((string) $keyName, $cache)->setBandedReferences((array) $fragments);
        }

        foreach ((array) ($data['entries'] ?? []) as $keyName => $value) {
            try {
                $this->importEntry((string) $keyName, (string) $value, $target, $cache)
                    ? $result['created']++
                    : $result['updated']++;
            } catch (\Throwable $e) {
                $result['errors'][] = "entries[{$keyName}]: " . $e->getMessage();
            }
        }

        $this->em->flush();

        return $result;
    }

    /**
     * @param array<string, TranslationKey> $cache
     * @return bool true = created, false = updated
     */
    private function importEntry(string $keyName, string $value, Language $target, array &$cache): bool
    {
        $existing = $cache[$keyName] ?? $this->translationKeyRepository->findOneByKey($keyName);

        if ($existing !== null && !$existing->isGeneric()) {
            throw new \InvalidArgumentException(
                "'{$keyName}' belongs to narrative content — edit it via that entity's Translations tab, not the generic catalogue."
            );
        }

        $key          = $existing ?? $this->ensureGenericKey($keyName, $cache);
        $wasTranslated = $this->translationRepository->findOneForKeyAndLanguage($key, $target) !== null;

        $this->translationRepository->upsert($key, $target, $value);

        return !$wasTranslated;
    }

    /** @param array<string, TranslationKey> $cache */
    private function ensureGenericKey(string $keyName, array &$cache): TranslationKey
    {
        if (isset($cache[$keyName])) {
            return $cache[$keyName];
        }

        $key = $this->translationKeyRepository->findOneByKey($keyName);
        if ($key === null) {
            $key = new TranslationKey($keyName);
            $this->em->persist($key);
        }

        return $cache[$keyName] = $key;
    }
}

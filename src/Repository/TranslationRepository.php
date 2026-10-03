<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Language;
use App\Entity\Translation;
use App\Entity\TranslationKey;
use App\Enum\TranslatableEntityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Translation>
 */
class TranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Translation::class);
    }

    public function findOneForKeyAndLanguage(TranslationKey $key, Language $language): ?Translation
    {
        return $this->findOneBy(['translationKey' => $key, 'language' => $language]);
    }

    /**
     * For the generic TranslationKey admin index's per-language presence columns — one query
     * for the whole page rather than one per (key, language) cell. Existence only, regardless
     * of value content (an empty-string Translation still counts as "translated" — see
     * Translation's own docblock on that distinction).
     *
     * @return array<int, array<string, true>> [translationKeyId => [languageCode => true]]
     */
    public function findGenericTranslationStatusMap(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('tk.id AS keyId', 'l.code AS languageCode')
            ->join('t.translationKey', 'tk')
            ->join('t.language', 'l')
            ->andWhere('tk.entityType IS NULL')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['keyId']][$row['languageCode']] = true;
        }

        return $map;
    }

    /**
     * Queries directly rather than reading $key->getTranslations(): that in-memory collection
     * is only populated by Doctrine's hydrator for a TranslationKey loaded FROM the database.
     * For one created earlier in the same request (e.g. ensureKeyFor() during a save,
     * followed by an export in the same request), its Translation children were persisted
     * via the owning side only — the inverse collection is never retroactively updated — so
     * reading it directly would silently see zero rows that really exist.
     *
     * @return Translation[]
     */
    public function findAllForKey(TranslationKey $key): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.translationKey = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getResult();
    }

    public function upsert(TranslationKey $key, Language $language, string $value): Translation
    {
        $translation = $this->findOneForKeyAndLanguage($key, $language);

        if ($translation === null) {
            $translation = new Translation($key, $language, $value);
            $this->getEntityManager()->persist($translation);
        } else {
            $translation->setValue($value);
        }

        return $translation;
    }

    /**
     * Deletes every generic (non-narrative) Translation row for one language — used by the
     * bulk-import screen's "clear existing" option. Scoped to a single language deliberately:
     * a bulk import only ever carries one language's file, so clearing every language (the
     * narrative import's "clear all" precedent) would destroy unrelated languages' work as
     * collateral damage. Narrative-linked keys are untouched regardless.
     *
     * DQL DELETE can't join, so this resolves generic key ids first, then deletes by id —
     * executes immediately as real SQL (not staged on the UnitOfWork), so a subsequent
     * findOneForKeyAndLanguage() in the same request correctly sees the row gone.
     */
    public function deleteGenericForLanguage(Language $language): int
    {
        $genericKeyIds = $this->getEntityManager()->createQueryBuilder()
            ->select('tk.id')
            ->from(TranslationKey::class, 'tk')
            ->andWhere('tk.entityType IS NULL')
            ->getQuery()
            ->getSingleColumnResult();

        if ($genericKeyIds === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.language = :language')
            ->andWhere('t.translationKey IN (:keyIds)')
            ->setParameter('language', $language)
            ->setParameter('keyIds', $genericKeyIds)
            ->getQuery()
            ->execute();
    }

    /**
     * One query covering every generic (non-narrative) key, with the requested language's
     * value left-joined alongside the default language's value so a missing requested value
     * falls back to default in a single pass — no per-key lookups.
     *
     * @return array{entries: array<string, string>, pluralSensitiveKeys: string[], bandedReferences: array<string, array<string, string>>}
     */
    public function getGenericCatalogueForLanguage(Language $requested, Language $default): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('tk.key AS key', 'tk.isPluralSensitive AS isPluralSensitive', 'tk.bandedReferences AS bandedReferences')
            ->addSelect('reqT.value AS requestedValue', 'defT.value AS defaultValue')
            ->from(TranslationKey::class, 'tk')
            ->leftJoin(Translation::class, 'reqT', 'WITH', 'reqT.translationKey = tk AND reqT.language = :requested')
            ->leftJoin(Translation::class, 'defT', 'WITH', 'defT.translationKey = tk AND defT.language = :default')
            ->andWhere('tk.entityType IS NULL')
            ->setParameter('requested', $requested)
            ->setParameter('default', $default)
            ->getQuery()
            ->getArrayResult();

        $entries             = [];
        $pluralSensitiveKeys = [];
        $bandedReferences    = [];

        foreach ($rows as $row) {
            $entries[$row['key']] = $row['requestedValue'] ?? $row['defaultValue'] ?? '';

            if ($row['isPluralSensitive']) {
                $pluralSensitiveKeys[] = $row['key'];
            }
            if (!empty($row['bandedReferences'])) {
                $bandedReferences[$row['key']] = $row['bandedReferences'];
            }
        }

        return [
            'entries'             => $entries,
            'pluralSensitiveKeys' => $pluralSensitiveKeys,
            'bandedReferences'    => $bandedReferences,
        ];
    }

    /**
     * Every stored (non-default-language) value for one narrative entity type and language,
     * for the localize-in-place API endpoints to batch-lookup against instead of querying
     * per field. The default (EN) value is never stored here — callers fall back to the
     * live entity value when a slug/field pair is absent from this map.
     *
     * @return array<string, array<string, string>> [entitySlug => [fieldName => value]]
     */
    public function findValuesForEntityTypeAndLanguage(TranslatableEntityType $type, Language $language): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('tk.entitySlug AS entitySlug', 'tk.fieldName AS fieldName', 't.value AS value')
            ->join('t.translationKey', 'tk')
            ->andWhere('tk.entityType = :type')
            ->andWhere('t.language = :language')
            ->setParameter('type', $type->value)
            ->setParameter('language', $language)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['entitySlug']][$row['fieldName']] = $row['value'];
        }

        return $map;
    }

    /**
     * Every stored value for one narrative entity row, across all languages — for the admin
     * "Translations" quick-edit screen. Keyed by language code then field name.
     *
     * @return array<string, array<string, string>> [languageCode => [fieldName => value]]
     */
    public function findValuesForEntity(TranslatableEntityType $type, string $slug): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('l.code AS languageCode', 'tk.fieldName AS fieldName', 't.value AS value')
            ->join('t.translationKey', 'tk')
            ->join('t.language', 'l')
            ->andWhere('tk.entityType = :type')
            ->andWhere('tk.entitySlug = :slug')
            ->setParameter('type', $type->value)
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['languageCode']][$row['fieldName']] = $row['value'];
        }

        return $map;
    }
}

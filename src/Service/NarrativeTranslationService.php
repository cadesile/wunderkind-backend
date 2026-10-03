<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Excursion;
use App\Entity\FacilityTemplate;
use App\Entity\GameEventTemplate;
use App\Entity\Language;
use App\Entity\PlayerArchetype;
use App\Entity\TranslationKey;
use App\Enum\TranslatableEntityType;
use App\Repository\ExcursionRepository;
use App\Repository\FacilityTemplateRepository;
use App\Repository\GameEventTemplateRepository;
use App\Repository\LanguageRepository;
use App\Repository\PlayerArchetypeRepository;
use App\Repository\TranslationKeyRepository;
use App\Repository\TranslationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Central find-or-create / lookup point for narrative-content translations (GameEventTemplate,
 * FacilityTemplate, Excursion, PlayerArchetype). Used by: the admin "Translations" quick-edit
 * screen, the localize-in-place API endpoints (?lang= on /api/events/templates,
 * /api/excursions, /api/game-config, /api/archetypes), the backfill command, and
 * NarrativeImportExportService's translations section.
 *
 * Deliberately NOT the source of the default-language (EN) value — that is always read live
 * off the owning entity's own getter. A stored EN mirror would go stale the instant an admin
 * edits the entity through its normal CRUD form, the same class of bug
 * NarrativeFacilityTemplateRoundTripTest's docblock documents for FacilityTemplate's own
 * export/import split.
 */
class NarrativeTranslationService
{
    /**
     * Which fields of each narrative entity type are translatable, and their getter/setter
     * pair. Hand-maintained, not reflection-driven — most columns on these entities (slug,
     * category, cost, …) are deliberately not translatable.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const TRANSLATABLE_FIELDS = [
        'game_event_template' => [
            'title'        => ['getTitle', 'setTitle'],
            'bodyTemplate' => ['getBodyTemplate', 'setBodyTemplate'],
        ],
        'facility_template' => [
            'label'       => ['getLabel', 'setLabel'],
            'description' => ['getDescription', 'setDescription'],
        ],
        'excursion' => [
            'title' => ['getTitle', 'setTitle'],
            'body'  => ['getBody', 'setBody'],
        ],
        'player_archetype' => [
            'name'        => ['getName', 'setName'],
            'description' => ['getDescription', 'setDescription'],
        ],
    ];

    public function __construct(
        private readonly GameEventTemplateRepository $eventTemplateRepository,
        private readonly FacilityTemplateRepository   $facilityTemplateRepository,
        private readonly ExcursionRepository           $excursionRepository,
        private readonly PlayerArchetypeRepository     $archetypeRepository,
        private readonly TranslationKeyRepository      $translationKeyRepository,
        private readonly TranslationRepository         $translationRepository,
        private readonly LanguageRepository            $languageRepository,
        private readonly EntityManagerInterface        $em,
    ) {}

    /** @return array<string, array{0: string, 1: string}> fieldName => [getter, setter] */
    public function getTranslatableFields(TranslatableEntityType $type): array
    {
        return self::TRANSLATABLE_FIELDS[$type->value] ?? [];
    }

    public function isTranslatableField(TranslatableEntityType $type, string $field): bool
    {
        return isset(self::TRANSLATABLE_FIELDS[$type->value][$field]);
    }

    public function resolveEntityBySlug(TranslatableEntityType $type, string $slug): GameEventTemplate|FacilityTemplate|Excursion|PlayerArchetype|null
    {
        return match ($type) {
            TranslatableEntityType::GAME_EVENT_TEMPLATE => $this->eventTemplateRepository->findOneBy(['slug' => $slug]),
            TranslatableEntityType::FACILITY_TEMPLATE   => $this->facilityTemplateRepository->findOneBy(['slug' => $slug]),
            TranslatableEntityType::EXCURSION           => $this->excursionRepository->findOneBy(['slug' => $slug]),
            TranslatableEntityType::PLAYER_ARCHETYPE    => $this->archetypeRepository->findOneBy(['slug' => $slug]),
        };
    }

    /**
     * GameEventTemplate/FacilityTemplate use UUID ids; Excursion/PlayerArchetype use a plain
     * autoincrement int id — branch here rather than assuming one id shape.
     */
    public function resolveEntityById(TranslatableEntityType $type, string $id): GameEventTemplate|FacilityTemplate|Excursion|PlayerArchetype|null
    {
        return match ($type) {
            TranslatableEntityType::GAME_EVENT_TEMPLATE => $this->eventTemplateRepository->find($id),
            TranslatableEntityType::FACILITY_TEMPLATE   => $this->facilityTemplateRepository->find($id),
            TranslatableEntityType::EXCURSION           => $this->excursionRepository->find((int) $id),
            TranslatableEntityType::PLAYER_ARCHETYPE    => $this->archetypeRepository->find((int) $id),
        };
    }

    public function getFieldValue(GameEventTemplate|FacilityTemplate|Excursion|PlayerArchetype $entity, string $field): string
    {
        $pair = self::TRANSLATABLE_FIELDS[$this->typeOf($entity)->value][$field] ?? null;
        if ($pair === null) {
            throw new \InvalidArgumentException("'{$field}' is not a translatable field for this entity type.");
        }

        [$getter] = $pair;

        return (string) $entity->{$getter}();
    }

    public function typeOf(GameEventTemplate|FacilityTemplate|Excursion|PlayerArchetype $entity): TranslatableEntityType
    {
        return match (true) {
            $entity instanceof GameEventTemplate => TranslatableEntityType::GAME_EVENT_TEMPLATE,
            $entity instanceof FacilityTemplate   => TranslatableEntityType::FACILITY_TEMPLATE,
            $entity instanceof Excursion          => TranslatableEntityType::EXCURSION,
            $entity instanceof PlayerArchetype    => TranslatableEntityType::PLAYER_ARCHETYPE,
        };
    }

    /**
     * Deliberately no instance-level cache here: a cache that outlives a single call could
     * go stale the moment something else (a direct removal, a different code path) deletes
     * the row it points to, then silently feed Doctrine a reference to a detached entity on
     * the next call — which surfaces as a confusing ORMInvalidArgumentException far from the
     * actual cause. Callers that risk asking for the same not-yet-existing key twice within
     * one call (saveTranslations(), across languages) must dedupe locally instead — see its
     * own docblock.
     */
    public function ensureKeyFor(TranslatableEntityType $type, string $slug, string $field): TranslationKey
    {
        if (!$this->isTranslatableField($type, $field)) {
            throw new \InvalidArgumentException("'{$field}' is not a translatable field for {$type->value}.");
        }

        $key = $this->translationKeyRepository->findOneForNarrativeField($type, $slug, $field);
        if ($key === null) {
            $key = new TranslationKey(sprintf('narrative.%s.%s.%s', $type->value, $slug, $field));
            $key->setEntityType($type->value);
            $key->setEntitySlug($slug);
            $key->setFieldName($field);
            $this->em->persist($key);
        }

        return $key;
    }

    /** Find-or-create a TranslationKey for every translatable field of one entity row. */
    public function ensureKeysForEntity(TranslatableEntityType $type, string $slug): void
    {
        foreach (array_keys($this->getTranslatableFields($type)) as $field) {
            $this->ensureKeyFor($type, $slug, $field);
        }
    }

    /**
     * Every stored (non-default-language) value for one entity type and language, for the
     * localize-in-place API endpoints to batch-lookup against. Returns [] for the default
     * language (or null) — there is nothing to look up, callers always fall back to the live
     * entity value.
     *
     * @return array<string, array<string, string>> [entitySlug => [fieldName => value]]
     */
    public function buildLocalizationMap(TranslatableEntityType $type, ?Language $language): array
    {
        if ($language === null || $language->isDefault()) {
            return [];
        }

        return $this->translationRepository->findValuesForEntityTypeAndLanguage($type, $language);
    }

    /** @param array<string, array<string, string>> $localizationMap from buildLocalizationMap() */
    public function localize(string $slug, string $field, string $defaultValue, array $localizationMap): string
    {
        return $localizationMap[$slug][$field] ?? $defaultValue;
    }

    /**
     * Every stored value for one entity row, across all configured languages — for the admin
     * "Translations" quick-edit screen.
     *
     * @return array<string, array<string, string>> [languageCode => [fieldName => value]]
     */
    public function findStoredValuesForEntity(TranslatableEntityType $type, string $slug): array
    {
        return $this->translationRepository->findValuesForEntity($type, $slug);
    }

    /**
     * Saves the admin quick-edit screen's submission. A blank (trimmed-empty) value clears
     * any existing translation for that language/field, reverting display to the default
     * (EN) value, rather than storing an empty-string override.
     *
     * Resolves each distinct field's TranslationKey exactly once, up front, rather than once
     * per (language, field) pair: a brand-new field submitted for two languages in the same
     * call would otherwise call ensureKeyFor() twice before either persist reaches the DB —
     * findOneForNarrativeField() can't see the first call's uncommitted insert, so the
     * second call creates a colliding duplicate. This local, call-scoped resolution (not an
     * instance-level cache — see ensureKeyFor()'s own docblock on why that's the wrong fix)
     * sidesteps it.
     *
     * @param array<string, array<string, string>> $valuesByLanguageCodeAndField [languageCode => [fieldName => value]]
     */
    public function saveTranslations(TranslatableEntityType $type, string $slug, array $valuesByLanguageCodeAndField): void
    {
        $fieldNames = [];
        foreach ($valuesByLanguageCodeAndField as $fields) {
            foreach (array_keys($fields) as $field) {
                $fieldNames[$field] = true;
            }
        }

        $keysByField = [];
        foreach (array_keys($fieldNames) as $field) {
            if ($this->isTranslatableField($type, $field)) {
                $keysByField[$field] = $this->ensureKeyFor($type, $slug, $field);
            }
        }

        foreach ($valuesByLanguageCodeAndField as $languageCode => $fields) {
            $language = $this->languageRepository->findByCode($languageCode);
            if ($language === null || $language->isDefault()) {
                continue;
            }

            foreach ($fields as $field => $value) {
                $key = $keysByField[$field] ?? null;
                if ($key === null) {
                    continue;
                }

                $value = trim($value);

                if ($value === '') {
                    $existing = $this->translationRepository->findOneForKeyAndLanguage($key, $language);
                    if ($existing !== null) {
                        $this->em->remove($existing);
                    }
                    continue;
                }

                $this->translationRepository->upsert($key, $language, $value);
            }
        }

        $this->em->flush();
    }
}

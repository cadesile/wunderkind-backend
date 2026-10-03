<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Excursion;
use App\Entity\FacilityTemplate;
use App\Entity\GameEventTemplate;
use App\Entity\PlayerArchetype;
use App\Entity\TacticalAdvantage;
use App\Enum\ArchetypePolarity;
use App\Enum\EventCategory;
use App\Enum\PlayingStyle;
use App\Enum\TranslatableEntityType;
use App\Repository\ExcursionRepository;
use App\Repository\FacilityTemplateRepository;
use App\Repository\GameEventTemplateRepository;
use App\Repository\LanguageRepository;
use App\Repository\PlayerArchetypeRepository;
use App\Repository\TacticalAdvantageRepository;
use App\Repository\TranslationKeyRepository;
use App\Repository\TranslationRepository;
use Doctrine\ORM\EntityManagerInterface;

class NarrativeImportExportService
{
    // Bumped from 2 to 3 when the `translations` section was added, and from 3 to 4 when
    // `excursions` was added. Unrelated to the generic UI-copy catalogue, which has its own
    // independent TranslationCatalogueImportExportService.
    private const EXPORT_VERSION = 4;

    public function __construct(
        private readonly GameEventTemplateRepository $eventTemplateRepository,
        private readonly FacilityTemplateRepository  $facilityTemplateRepository,
        private readonly PlayerArchetypeRepository   $archetypeRepository,
        private readonly TacticalAdvantageRepository $tacticalAdvantageRepository,
        private readonly ExcursionRepository         $excursionRepository,
        private readonly TranslationKeyRepository    $translationKeyRepository,
        private readonly TranslationRepository       $translationRepository,
        private readonly LanguageRepository          $languageRepository,
        private readonly NarrativeTranslationService $translationService,
        private readonly EntityManagerInterface      $em,
    ) {}

    // ── Export ────────────────────────────────────────────────────────────────

    public function export(): array
    {
        return [
            'version'            => self::EXPORT_VERSION,
            'exportedAt'         => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'eventTemplates'     => $this->exportEventTemplates(),
            'facilityTemplates'  => $this->exportFacilityTemplates(),
            'playerArchetypes'   => $this->exportPlayerArchetypes(),
            'tacticalAdvantages' => $this->exportTacticalAdvantages(),
            'excursions'         => $this->exportExcursions(),
            'translations'       => $this->exportTranslations(),
        ];
    }

    private function exportEventTemplates(): array
    {
        return array_map(fn (GameEventTemplate $t) => [
            'slug'             => $t->getSlug(),
            'category'         => $t->getCategory()->value,
            'weight'           => $t->getWeight(),
            'title'            => $t->getTitle(),
            'bodyTemplate'     => $t->getBodyTemplate(),
            'impacts'          => $t->getImpacts(),
            'firingConditions' => $t->getFiringConditions(),
            'severity'         => $t->getSeverity(),
            'chainedEvents'    => $t->getChainedEvents(),
            'noInteract'       => $t->isNoInteract(),
        ], $this->eventTemplateRepository->findBy([], ['category' => 'ASC', 'slug' => 'ASC']));
    }

    private function exportFacilityTemplates(): array
    {
        return array_map(fn (FacilityTemplate $t) => $t->toArray(),
            $this->facilityTemplateRepository->findBy([], ['sortOrder' => 'ASC', 'slug' => 'ASC']));
    }

    private function exportPlayerArchetypes(): array
    {
        return array_map(fn (PlayerArchetype $a) => [
            'slug'         => $a->getSlug(),
            'name'         => $a->getName(),
            'description'  => $a->getDescription(),
            'polarity'     => $a->getPolarity()->value,
            'traitWeights' => $a->getTraitWeights(),
        ], $this->archetypeRepository->findBy([], ['polarity' => 'ASC', 'slug' => 'ASC']));
    }

    private function exportTacticalAdvantages(): array
    {
        return array_map(fn (TacticalAdvantage $t) => [
            'style'         => $t->getStyle()->value,
            'opponentStyle' => $t->getOpponentStyle()->value,
            'multiplier'    => $t->getMultiplier(),
        ], $this->tacticalAdvantageRepository->findBy([], ['style' => 'ASC', 'opponentStyle' => 'ASC']));
    }

    private function exportExcursions(): array
    {
        return array_map(fn (Excursion $e) => $e->toArray(),
            $this->excursionRepository->findBy([], ['slug' => 'ASC']));
    }

    /**
     * Non-default-language values only, for GameEventTemplate/FacilityTemplate/Excursion
     * text fields. The default (EN) value is never exported here — it's already carried by
     * the existing sections' own title/bodyTemplate/label/description fields above.
     */
    private function exportTranslations(): array
    {
        $default = $this->languageRepository->findDefault();
        $rows    = [];

        foreach (TranslatableEntityType::cases() as $type) {
            foreach ($this->translationKeyRepository->findAllForEntityType($type) as $key) {
                $values = [];
                foreach ($this->translationRepository->findAllForKey($key) as $translation) {
                    $language = $translation->getLanguage();
                    if ($default !== null && $language->getId() === $default->getId()) {
                        continue;
                    }
                    $values[$language->getCode()] = $translation->getValue();
                }

                if ($values === []) {
                    continue;
                }

                $rows[] = [
                    'entityType' => $key->getEntityType(),
                    'slug'       => $key->getEntitySlug(),
                    'field'      => $key->getFieldName(),
                    'values'     => $values,
                ];
            }
        }

        return $rows;
    }

    // ── Import ────────────────────────────────────────────────────────────────

    /**
     * Also purges narrative-linked TranslationKey rows (cascading to their Translation
     * children via orphanRemoval) for every type whose base rows this method wipes above —
     * GameEventTemplate, FacilityTemplate, PlayerArchetype, and (as of Excursion joining the
     * bulk export/import below) Excursion too. Previously Excursion rows themselves were
     * deliberately NOT wiped here, so purging its translation keys would have orphaned
     * translations for excursions that still existed — now that Excursion is a fully
     * round-trippable member of this export/import set like the other three, that asymmetry
     * no longer applies: "clear existing data before importing" means the same thing for all
     * of them.
     */
    public function clearAll(): void
    {
        foreach ($this->eventTemplateRepository->findAll() as $e) {
            $this->em->remove($e);
        }
        foreach ($this->facilityTemplateRepository->findAll() as $f) {
            $this->em->remove($f);
        }
        foreach ($this->archetypeRepository->findAll() as $a) {
            $this->em->remove($a);
        }
        foreach ($this->tacticalAdvantageRepository->findAll() as $t) {
            $this->em->remove($t);
        }
        foreach ($this->excursionRepository->findAll() as $x) {
            $this->em->remove($x);
        }
        foreach ($this->translationKeyRepository->findAllForEntityType(TranslatableEntityType::GAME_EVENT_TEMPLATE) as $k) {
            $this->em->remove($k);
        }
        foreach ($this->translationKeyRepository->findAllForEntityType(TranslatableEntityType::FACILITY_TEMPLATE) as $k) {
            $this->em->remove($k);
        }
        foreach ($this->translationKeyRepository->findAllForEntityType(TranslatableEntityType::PLAYER_ARCHETYPE) as $k) {
            $this->em->remove($k);
        }
        foreach ($this->translationKeyRepository->findAllForEntityType(TranslatableEntityType::EXCURSION) as $k) {
            $this->em->remove($k);
        }
        $this->em->flush();
    }

    /**
     * @return array{created: int, updated: int, errors: string[]}
     */
    public function import(array $data): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];

        if (($data['version'] ?? null) !== self::EXPORT_VERSION) {
            $result['errors'][] = 'Unsupported export version — expected version ' . self::EXPORT_VERSION;
            return $result;
        }

        foreach ($data['eventTemplates'] ?? [] as $row) {
            try {
                $this->upsertEventTemplate($row)
                    ? $result['created']++
                    : $result['updated']++;
            } catch (\Throwable $e) {
                $result['errors'][] = 'eventTemplate[' . ($row['slug'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        foreach ($data['facilityTemplates'] ?? [] as $row) {
            try {
                $this->upsertFacilityTemplate($row)
                    ? $result['created']++
                    : $result['updated']++;
            } catch (\Throwable $e) {
                $result['errors'][] = 'facilityTemplate[' . ($row['slug'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        foreach ($data['playerArchetypes'] ?? [] as $row) {
            try {
                $this->upsertPlayerArchetype($row)
                    ? $result['created']++
                    : $result['updated']++;
            } catch (\Throwable $e) {
                $result['errors'][] = 'playerArchetype[' . ($row['slug'] ?? $row['name'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        foreach ($data['tacticalAdvantages'] ?? [] as $row) {
            try {
                $this->upsertTacticalAdvantage($row)
                    ? $result['created']++
                    : $result['updated']++;
            } catch (\Throwable $e) {
                $result['errors'][] = 'tacticalAdvantage[' . ($row['style'] ?? '?') . ' vs ' . ($row['opponentStyle'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        foreach ($data['excursions'] ?? [] as $row) {
            try {
                $this->upsertExcursion($row)
                    ? $result['created']++
                    : $result['updated']++;
            } catch (\Throwable $e) {
                $result['errors'][] = 'excursion[' . ($row['slug'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        foreach ($data['translations'] ?? [] as $row) {
            $rowResult = $this->upsertTranslationRow($row);
            $result['created'] += $rowResult['created'];
            $result['updated'] += $rowResult['updated'];
            $result['errors']   = [...$result['errors'], ...$rowResult['errors']];
        }

        $this->em->flush();

        return $result;
    }

    /** @return bool true = created, false = updated */
    private function upsertEventTemplate(array $row): bool
    {
        $slug = trim($row['slug'] ?? '');
        if ($slug === '') throw new \InvalidArgumentException('Missing slug.');

        $category = EventCategory::tryFrom($row['category'] ?? '');
        if ($category === null) throw new \InvalidArgumentException("Unknown category '{$row['category']}'.");

        $template = $this->eventTemplateRepository->findOneBy(['slug' => $slug]);
        $created  = $template === null;

        if ($created) {
            $template = new GameEventTemplate();
            $template->setSlug($slug);
        }

        $template->setCategory($category);
        $template->setWeight((int) ($row['weight'] ?? 1));
        $template->setTitle($row['title'] ?? '');
        $template->setBodyTemplate($row['bodyTemplate'] ?? '');
        $template->setImpacts($row['impacts'] ?? []);
        $template->setFiringConditions($row['firingConditions'] ?? null);
        $template->setSeverity($row['severity'] ?? null);
        $template->setChainedEvents($row['chainedEvents'] ?? null);
        $template->setNoInteract((bool) ($row['noInteract'] ?? false));

        // Persisted only once the row is known-good, so a rejected row leaves nothing behind.
        if ($created) {
            $this->em->persist($template);
        }

        return $created;
    }

    /** @return bool true = created, false = updated */
    private function upsertFacilityTemplate(array $row): bool
    {
        $slug = trim($row['slug'] ?? '');
        if ($slug === '') throw new \InvalidArgumentException('Missing slug.');

        $template = $this->facilityTemplateRepository->findOneBy(['slug' => $slug]);
        $created  = $template === null;

        if ($created) {
            $template = new FacilityTemplate();
        }

        $template->setSlug($slug);
        $template->setLabel($row['label'] ?? $slug);
        $template->setDescription($row['description'] ?? '');
        $template->setCategory($row['category'] ?? 'TRAINING');
        $template->setBaseCost((int) ($row['baseCost'] ?? 0));
        $template->setWeeklyUpkeepBase((int) ($row['weeklyUpkeepBase'] ?? 0));
        $template->setMatchdayIncome(isset($row['matchdayIncome']) ? (int) $row['matchdayIncome'] : null);
        $template->setMatchdayIncomeMultiplier(isset($row['matchdayIncomeMultiplier']) ? (float) $row['matchdayIncomeMultiplier'] : null);
        $template->setReputationBonus((float) ($row['reputationBonus'] ?? 0));
        $template->setMaxLevel((int) ($row['maxLevel'] ?? 5));
        $template->setDecayBase((float) ($row['decayBase'] ?? 2.0));
        $template->setBaseConstructionWeeks((int) ($row['baseConstructionWeeks'] ?? 4));
        $template->setSortOrder((int) ($row['sortOrder'] ?? 0));
        $template->setIsActive((bool) ($row['isActive'] ?? true));
        $template->setGameplayEffects((array) ($row['gameplayEffects'] ?? []));
        if (array_key_exists('imagePath', $row)) {
            $template->setImagePath($row['imagePath'] !== null ? basename((string) $row['imagePath']) : null);
        }
        $template->touch();

        if ($created) {
            $this->em->persist($template);
        }

        return $created;
    }

    /** @return bool true = created, false = updated */
    private function upsertPlayerArchetype(array $row): bool
    {
        // Match on slug, not name: slug is the stable machine identity, and name-matching is
        // why re-importing a pre-2026-08 export duplicated rows instead of updating them.
        $slug = trim($row['slug'] ?? '');
        if ($slug === '') throw new \InvalidArgumentException('Missing slug.');

        $rawPolarity = $row['polarity'] ?? '';
        $polarity    = ArchetypePolarity::tryFrom(is_string($rawPolarity) ? $rawPolarity : '');
        if ($polarity === null) {
            throw new \InvalidArgumentException(sprintf(
                'Archetype "%s" has an invalid polarity "%s" — expected "positive" or "negative".',
                $slug,
                is_scalar($rawPolarity) ? (string) $rawPolarity : gettype($rawPolarity),
            ));
        }

        $archetype = $this->archetypeRepository->findBySlug($slug);
        $created   = $archetype === null;

        if ($created) {
            $archetype = new PlayerArchetype();
        }

        $archetype->setSlug($slug);
        $archetype->setName(trim($row['name'] ?? '') ?: $slug);
        $archetype->setDescription($row['description'] ?? '');
        $archetype->setPolarity($polarity);
        // `traitMapping` is the pre-v2 key name — accepted so older exports still load.
        $archetype->setTraitWeights($row['traitWeights'] ?? $row['traitMapping'] ?? []);

        if ($created) {
            $this->em->persist($archetype);
        }

        return $created;
    }

    /** @return bool true = created, false = updated */
    private function upsertTacticalAdvantage(array $row): bool
    {
        $style         = PlayingStyle::tryFrom($row['style'] ?? '');
        $opponentStyle = PlayingStyle::tryFrom($row['opponentStyle'] ?? '');

        if ($style === null || $opponentStyle === null) {
            throw new \InvalidArgumentException('Invalid style or opponentStyle.');
        }

        $advantage = $this->tacticalAdvantageRepository->findOneBy([
            'style'         => $style,
            'opponentStyle' => $opponentStyle,
        ]);
        $created = $advantage === null;

        if ($created) {
            $advantage = new TacticalAdvantage($style, $opponentStyle);
        }

        $advantage->setMultiplier((float) ($row['multiplier'] ?? 1.0));

        if ($created) {
            $this->em->persist($advantage);
        }

        return $created;
    }

    /** @return bool true = created, false = updated */
    private function upsertExcursion(array $row): bool
    {
        $slug = trim($row['slug'] ?? '');
        if ($slug === '') throw new \InvalidArgumentException('Missing slug.');

        $excursion = $this->excursionRepository->findOneBy(['slug' => $slug]);
        $created   = $excursion === null;

        if ($created) {
            $excursion = new Excursion($slug);
        }

        $excursion->setSlug($slug);
        $excursion->setTitle($row['title'] ?? $slug);
        $excursion->setBody($row['body'] ?? '');
        if (array_key_exists('imagePath', $row)) {
            $excursion->setImagePath($row['imagePath'] !== null ? basename((string) $row['imagePath']) : null);
        }
        $excursion->setCostPerPersonPence((int) ($row['costPerPersonPence'] ?? 0));
        $excursion->setEffectValue((int) ($row['effectValue'] ?? 50));
        $excursion->setNegativeFrequency((int) ($row['negativeFrequency'] ?? 5));
        $excursion->setTargetAudience($row['targetAudience'] ?? Excursion::AUDIENCE_BOTH);
        $excursion->setPostSeasonOnly((bool) ($row['postSeasonOnly'] ?? false));
        $excursion->setCooldownWeeks((int) ($row['cooldownWeeks'] ?? 4));
        $excursion->setActive((bool) ($row['active'] ?? true));

        if ($created) {
            $this->em->persist($excursion);
        }

        return $created;
    }

    /**
     * One row covers every language value for one narrative field, so success/failure is
     * tracked per language rather than per row — one bad language code shouldn't discard the
     * others.
     *
     * @return array{created: int, updated: int, errors: string[]}
     */
    private function upsertTranslationRow(array $row): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];

        $type = TranslatableEntityType::tryFrom($row['entityType'] ?? '');
        $slug = trim($row['slug'] ?? '');
        $field = trim($row['field'] ?? '');
        $label = ($row['entityType'] ?? '?') . '[' . ($slug ?: '?') . '].' . ($field ?: '?');

        if ($type === null || $slug === '' || !$this->translationService->isTranslatableField($type, $field)) {
            $result['errors'][] = "translation[{$label}]: unknown entityType/field.";
            return $result;
        }

        if ($this->translationService->resolveEntityBySlug($type, $slug) === null) {
            $result['errors'][] = "translation[{$label}]: no {$type->value} with slug '{$slug}' exists on this system.";
            return $result;
        }

        $key = $this->translationService->ensureKeyFor($type, $slug, $field);

        foreach ((array) ($row['values'] ?? []) as $code => $value) {
            $language = $this->languageRepository->findByCode((string) $code);
            if ($language === null) {
                $result['errors'][] = "translation[{$label}]: unknown language code '{$code}'.";
                continue;
            }

            $wasTranslated = $this->translationRepository->findOneForKeyAndLanguage($key, $language) !== null;
            $this->translationRepository->upsert($key, $language, (string) $value);

            $wasTranslated ? $result['updated']++ : $result['created']++;
        }

        return $result;
    }
}

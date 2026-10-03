<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Excursion;
use App\Entity\FacilityTemplate;
use App\Entity\GameEventTemplate;
use App\Entity\Language;
use App\Enum\EventCategory;
use App\Enum\TranslatableEntityType;
use App\Repository\ExcursionRepository;
use App\Repository\FacilityTemplateRepository;
use App\Repository\GameEventTemplateRepository;
use App\Repository\LanguageRepository;
use App\Repository\TranslationKeyRepository;
use App\Service\NarrativeImportExportService;
use App\Service\NarrativeTranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Unlike NarrativeFacilityTemplateRoundTripTest's full reflective ORM-column sweep, this
 * guards a narrower contract: every field named in
 * NarrativeTranslationService::TRANSLATABLE_FIELDS has a working getter/setter on its
 * entity. Most columns on GameEventTemplate/FacilityTemplate/Excursion are deliberately NOT
 * translatable (slug, category, cost, …), so a full column sweep would be the wrong pattern
 * here — don't "fix" this test into one.
 */
class NarrativeTranslationRoundTripTest extends KernelTestCase
{
    private const DEFAULT_CODE = 'xx';
    private const OTHER_CODE   = 'yy';
    private const SLUG         = 'round-trip-probe-translation';

    private NarrativeImportExportService $importExportService;
    private NarrativeTranslationService  $translationService;
    private LanguageRepository           $languageRepository;
    private TranslationKeyRepository     $translationKeyRepository;
    private GameEventTemplateRepository  $eventTemplateRepository;
    private FacilityTemplateRepository   $facilityTemplateRepository;
    private ExcursionRepository          $excursionRepository;
    private EntityManagerInterface       $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->importExportService       = $container->get(NarrativeImportExportService::class);
        $this->translationService        = $container->get(NarrativeTranslationService::class);
        $this->languageRepository         = $container->get(LanguageRepository::class);
        $this->translationKeyRepository   = $container->get(TranslationKeyRepository::class);
        $this->eventTemplateRepository    = $container->get(GameEventTemplateRepository::class);
        $this->facilityTemplateRepository = $container->get(FacilityTemplateRepository::class);
        $this->excursionRepository        = $container->get(ExcursionRepository::class);
        $this->em                         = $container->get(EntityManagerInterface::class);

        $this->removeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();
        parent::tearDown();
    }

    public function testEveryTranslatableFieldHasAWorkingGetterAndSetter(): void
    {
        $entitiesByType = [
            TranslatableEntityType::GAME_EVENT_TEMPLATE->value => new GameEventTemplate(),
            TranslatableEntityType::FACILITY_TEMPLATE->value   => new FacilityTemplate(),
            TranslatableEntityType::EXCURSION->value           => new Excursion(),
        ];

        foreach (TranslatableEntityType::cases() as $type) {
            $entity = $entitiesByType[$type->value];

            foreach ($this->translationService->getTranslatableFields($type) as $field => [$getter, $setter]) {
                self::assertTrue(
                    method_exists($entity, $getter) && method_exists($entity, $setter),
                    "{$type->value}.{$field} declares [{$getter}, {$setter}] but one of them does not exist.",
                );

                $probe = 'probe-' . $field;
                $entity->{$setter}($probe);
                self::assertSame(
                    $probe,
                    $entity->{$getter}(),
                    "{$type->value}.{$field}'s setter/getter pair does not round-trip.",
                );
            }
        }
    }

    public function testNonDefaultLanguageValuesRoundTripThroughExportAndImport(): void
    {
        $default = new Language(self::DEFAULT_CODE, 'Probe Default');
        $default->setIsDefault(true);
        $other = new Language(self::OTHER_CODE, 'Probe Other');
        $this->em->persist($default);
        $this->em->persist($other);

        $event      = new GameEventTemplate();
        $event->setSlug(self::SLUG);
        $event->setCategory(EventCategory::PLAYER_MORALE);
        $event->setTitle('EN title');
        $event->setBodyTemplate('EN body');
        $this->em->persist($event);

        $facility = new FacilityTemplate(self::SLUG, 'EN label', 'EN description', 'TRAINING', 100);
        $this->em->persist($facility);

        $excursion = new Excursion(self::SLUG, 'EN title', 'EN body');
        $this->em->persist($excursion);

        $this->em->flush();

        $this->translationService->saveTranslations(TranslatableEntityType::GAME_EVENT_TEMPLATE, self::SLUG, [
            self::OTHER_CODE => ['title' => 'Other title', 'bodyTemplate' => 'Other body'],
        ]);
        $this->translationService->saveTranslations(TranslatableEntityType::FACILITY_TEMPLATE, self::SLUG, [
            self::OTHER_CODE => ['label' => 'Other label', 'description' => 'Other description'],
        ]);
        $this->translationService->saveTranslations(TranslatableEntityType::EXCURSION, self::SLUG, [
            self::OTHER_CODE => ['title' => 'Other title', 'body' => 'Other body'],
        ]);

        $exported = $this->importExportService->export();

        // Clear just the Translation/TranslationKey rows this probe created — not the
        // narrative rows themselves — then re-import over the top, so the test actually
        // proves import recreates them rather than merely updating survivors.
        foreach (TranslatableEntityType::cases() as $type) {
            foreach ($this->translationKeyRepository->findForEntity($type, self::SLUG) as $key) {
                $this->em->remove($key);
            }
        }
        $this->em->flush();
        $this->em->clear();

        $result = $this->importExportService->import($exported);

        self::assertSame([], $result['errors']);

        $this->assertStoredValue(TranslatableEntityType::GAME_EVENT_TEMPLATE, 'title', 'Other title');
        $this->assertStoredValue(TranslatableEntityType::GAME_EVENT_TEMPLATE, 'bodyTemplate', 'Other body');
        $this->assertStoredValue(TranslatableEntityType::FACILITY_TEMPLATE, 'label', 'Other label');
        $this->assertStoredValue(TranslatableEntityType::FACILITY_TEMPLATE, 'description', 'Other description');
        $this->assertStoredValue(TranslatableEntityType::EXCURSION, 'title', 'Other title');
        $this->assertStoredValue(TranslatableEntityType::EXCURSION, 'body', 'Other body');
    }

    private function assertStoredValue(TranslatableEntityType $type, string $field, string $expected): void
    {
        $stored = $this->translationService->findStoredValuesForEntity($type, self::SLUG);
        self::assertSame(
            $expected,
            $stored[self::OTHER_CODE][$field] ?? null,
            "{$type->value}.{$field} did not survive the export/import round trip.",
        );
    }

    private function removeFixtures(): void
    {
        foreach ($this->eventTemplateRepository->findBy(['slug' => self::SLUG]) as $e) {
            $this->em->remove($e);
        }
        foreach ($this->facilityTemplateRepository->findBy(['slug' => self::SLUG]) as $f) {
            $this->em->remove($f);
        }
        foreach ($this->excursionRepository->findBy(['slug' => self::SLUG]) as $x) {
            $this->em->remove($x);
        }
        foreach (TranslatableEntityType::cases() as $type) {
            foreach ($this->translationKeyRepository->findForEntity($type, self::SLUG) as $key) {
                $this->em->remove($key);
            }
        }
        foreach ([self::DEFAULT_CODE, self::OTHER_CODE] as $code) {
            $language = $this->languageRepository->findByCode($code);
            if ($language !== null) {
                $this->em->remove($language);
            }
        }
        $this->em->flush();
    }
}

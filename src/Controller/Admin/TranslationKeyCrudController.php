<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TranslationKey;
use App\Filter\IncompleteTranslationFilter;
use App\Repository\LanguageRepository;
use App\Repository\TranslationKeyRepository;
use App\Repository\TranslationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\TextAlign;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Generic (UI-copy) translation keys only — narrative-linked keys (GameEventTemplate/
 * FacilityTemplate/Excursion text) are intentionally excluded here and managed only via
 * each entity's own "Translations" action (see NarrativeTranslationController), not this
 * screen.
 *
 * The index is grouped by key with one green/red presence column per enabled language,
 * rather than EasyAdmin's default one-row-per-entity grid — this entity already IS "one row
 * per key", so a virtual field per language (computed via formatValue(), not a real column)
 * gets the grouped view "for free" while keeping EasyAdmin's own pagination/search. Uses
 * TextField, not BooleanField: BooleanField's own index template reads `field.value` (the
 * raw property) directly rather than the formatValue()-produced `field.formattedValue`, so
 * it can't render a computed value at all — TextField's template does `{{
 * field.formattedValue|raw }}`, which is also what lets the icon HTML below render unescaped.
 */
class TranslationKeyCrudController extends AbstractCrudController
{
    /** Populated once per index request by configureFields(), read by each language field's formatValue() closure below. */
    private array $translationStatusMap = [];

    public function __construct(
        private readonly LanguageRepository       $languageRepository,
        private readonly TranslationRepository    $translationRepository,
        private readonly TranslationKeyRepository $translationKeyRepository,
    ) {}

    public static function getEntityFqcn(): string
    {
        return TranslationKey::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Translation Key')
            ->setEntityLabelInPlural('Translation Keys')
            ->setDefaultSort(['key' => 'ASC'])
            ->setHelp('index', 'Generic UI-copy keys only. Event/facility/excursion text is translated from that entity\'s own "Translations" action instead. Green = a value exists for that language; red = it falls back to the default language.')
            ->overrideTemplate('crud/edit', 'admin/translation_key_edit.html.twig');
    }

    /**
     * Injects the per-language editing grid rendered below the normal form — see
     * templates/admin/translation_key_edit.html.twig, which overrides `main` (calling
     * {{ parent() }} first) so the real key/isPluralSensitive/bandedReferences form is
     * untouched and still submits normally. Same "override one block of a standard CRUD
     * template" pattern as UserCrudController::edit()'s clubs panel — there it's
     * content_header_wrapper (above the form); here it's main (below it).
     *
     * The grid itself saves over AJAX via saveLanguage() below, not this form — each
     * language's textarea gets its own Save button hitting that endpoint directly, never a
     * whole-page submit.
     */
    public function edit(AdminContext $context): KeyValueStore|Response
    {
        $responseParameters = parent::edit($context);
        if ($responseParameters instanceof KeyValueStore) {
            /** @var TranslationKey $key */
            $key = $context->getEntity()->getInstance();

            $responseParameters->set('translationLanguages', $this->languageRepository->findEnabled());
            $responseParameters->set('translationValues', $this->translationRepository->findValuesForKey($key));
        }

        return $responseParameters;
    }

    /**
     * AJAX save target for one language's value on this key — always redirects/returns JSON,
     * never renders an @EasyAdmin-extending template itself, so (per
     * src/Controller/Admin/CLAUDE.md) it's exempt from the /admin?routeName=... wrapping and
     * can be hit directly from JS. A blank submitted value clears any existing translation
     * (reverting display to the default language) rather than storing an empty override —
     * same convention as NarrativeTranslationService::saveTranslations().
     */
    #[Route('/admin/translation-key/{id}/save-language/{code}', name: 'admin_translation_key_save_language', methods: ['POST'])]
    public function saveLanguage(int $id, string $code, Request $request, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('translation_key_save_language', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        $key = $this->translationKeyRepository->find($id);
        if ($key === null || !$key->isGeneric()) {
            return new JsonResponse(['error' => 'Translation key not found.'], 404);
        }

        $language = $this->languageRepository->findByCode($code);
        if ($language === null) {
            return new JsonResponse(['error' => "Unknown language '{$code}'."], 404);
        }

        $value = trim((string) $request->request->get('value', ''));

        if ($value === '') {
            $existing = $this->translationRepository->findOneForKeyAndLanguage($key, $language);
            if ($existing !== null) {
                $em->remove($existing);
                $em->flush();
            }

            return new JsonResponse(['status' => 'cleared']);
        }

        $this->translationRepository->upsert($key, $language, $value);
        $em->flush();

        return new JsonResponse(['status' => 'saved', 'value' => $value]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(IncompleteTranslationFilter::new());
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        // EasyAdmin's EntityRepository::createQueryBuilder() always roots the index query at
        // the 'entity' alias — see vendor/easycorp/easyadmin-bundle/src/Orm/EntityRepository.php.
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.entityType IS NULL');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('key')
            ->setHelp('The literal key the client looks up, e.g. component.excursionsPane.text.4');

        if ($pageName === Crud::PAGE_INDEX) {
            // One query for the whole page, not one per (key, language) cell.
            $this->translationStatusMap = $this->translationRepository->findGenericTranslationStatusMap();

            foreach ($this->languageRepository->findEnabled() as $language) {
                $code = $language->getCode();
                yield TextField::new('translated_' . $code, $language->getName())
                    // 'translated_<code>' isn't a real property — without a non-null
                    // placeholder here, EasyAdmin's CommonPreConfigurator tries (and fails)
                    // to read it via PropertyAccessor and renders its "inaccessible" error
                    // template regardless of what formatValue() below returns. Any non-null
                    // value here is enough to skip that path entirely.
                    ->setValue('')
                    ->setTextAlign(TextAlign::CENTER)
                    ->formatValue(fn ($value, TranslationKey $key) => ($this->translationStatusMap[$key->getId()][$code] ?? false)
                        ? '<i class="fa fa-check-circle text-success" title="Translated"></i>'
                        : '<i class="fa fa-times-circle text-danger" title="Not translated — falls back to the default language"></i>');
            }
        }

        yield BooleanField::new('isPluralSensitive')
            ->setHelp('Whether the client\'s rendering engine must apply plural-sensitive handling to this key. Structural, not a translated value.')
            ->hideOnIndex();
        yield CodeEditorField::new('bandedReferencesJson', 'Banded References')
            ->setLanguage('js')
            ->setNumOfRows(8)
            ->setHelp('Fragment-name => other-key-name map describing how the client composes this key\'s text from other keys\' sub-fragments. Leave empty (or {}) for none.')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('updatedAt')->hideOnForm()->hideOnIndex();
    }
}

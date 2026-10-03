<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\TranslatableEntityType;
use App\Repository\LanguageRepository;
use App\Service\NarrativeTranslationService;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The "Translations" quick-edit screen linked from GameEventTemplateCrudController/
 * FacilityTemplateCrudController/ExcursionCrudController/PlayerArchetypeCrudController's row
 * actions. The default (EN) column is read-only here — editing EN happens on the entity's own
 * normal edit form, since EN is read live off the entity, never stored as a Translation row
 * (see NarrativeTranslationService).
 */
#[IsGranted('ROLE_ADMIN')]
class NarrativeTranslationController extends AbstractController
{
    /** @var array<string, class-string> */
    private const CRUD_CONTROLLERS = [
        'game_event_template' => GameEventTemplateCrudController::class,
        'facility_template'   => FacilityTemplateCrudController::class,
        'excursion'            => ExcursionCrudController::class,
        'player_archetype'     => PlayerArchetypeCrudController::class,
    ];

    public function __construct(
        private readonly NarrativeTranslationService $translationService,
        private readonly LanguageRepository           $languageRepository,
        private readonly AdminUrlGenerator             $adminUrlGenerator,
    ) {}

    #[Route('/admin/narrative-translations/{entityType}/{id}', name: 'admin_narrative_translation_edit', methods: ['GET'])]
    public function edit(string $entityType, string $id): Response
    {
        $type   = $this->resolveType($entityType);
        $entity = $this->translationService->resolveEntityById($type, $id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }

        $slug   = $entity->getSlug();
        $fields = array_keys($this->translationService->getTranslatableFields($type));

        $defaultValues = [];
        foreach ($fields as $field) {
            $defaultValues[$field] = $this->translationService->getFieldValue($entity, $field);
        }

        return $this->render('admin/narrative_translations_edit.html.twig', [
            'entityType'    => $type->value,
            'entityId'      => $id,
            'slug'          => $slug,
            'fields'        => $fields,
            'defaultValues' => $defaultValues,
            'languages'     => $this->languageRepository->findEnabled(),
            'storedValues'  => $this->translationService->findStoredValuesForEntity($type, $slug),
            'backUrl'       => $this->adminUrlGenerator->setController(self::CRUD_CONTROLLERS[$type->value])->generateUrl(),
            // admin_narrative_translation_save always redirects, never renders an
            // @EasyAdmin-extending template itself, so (per src/Controller/Admin/CLAUDE.md)
            // it's exempt from the /admin?routeName=... wrapping and can be a plain POST
            // target — same precedent as admin_narrative_import's form action.
            'saveUrl'       => $this->generateUrl('admin_narrative_translation_save', ['entityType' => $type->value, 'id' => $id]),
        ]);
    }

    #[Route('/admin/narrative-translations/{entityType}/{id}/save', name: 'admin_narrative_translation_save', methods: ['POST'])]
    public function save(string $entityType, string $id, Request $request): Response
    {
        $type   = $this->resolveType($entityType);
        $entity = $this->translationService->resolveEntityById($type, $id);
        if ($entity === null) {
            throw $this->createNotFoundException();
        }

        $editUrl = $this->generateUrl('admin', [
            'routeName'   => 'admin_narrative_translation_edit',
            'routeParams' => ['entityType' => $type->value, 'id' => $id],
        ]);

        if (!$this->isCsrfTokenValid('narrative_translation_save', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($editUrl);
        }

        /** @var array<string, array<string, string>> $values [languageCode => [field => value]] */
        $values = (array) $request->request->all('values');
        $this->translationService->saveTranslations($type, $entity->getSlug(), $values);

        $this->addFlash('success', 'Translations saved.');

        return $this->redirect($editUrl);
    }

    private function resolveType(string $entityType): TranslatableEntityType
    {
        $type = TranslatableEntityType::tryFrom($entityType);
        if ($type === null || !isset(self::CRUD_CONTROLLERS[$type->value])) {
            throw $this->createNotFoundException("Unknown entity type '{$entityType}'.");
        }

        return $type;
    }
}

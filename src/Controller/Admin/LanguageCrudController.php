<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Language;
use App\Repository\LanguageRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Exactly one Language should have isDefault = true at any time. That's enforced here
 * (unsetting every other row on save), not with a DB constraint — this is low-frequency,
 * single-admin CRUD, not a concurrency-sensitive path like ActiveCompetition's partial
 * unique index.
 */
class LanguageCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly LanguageRepository      $languageRepository,
        private readonly EntityManagerInterface  $em,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Language::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Language')
            ->setEntityLabelInPlural('Languages')
            ->setDefaultSort(['sortOrder' => 'ASC', 'code' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('code')
            ->setHelp('2-letter code, e.g. "en", "fr". Treated as immutable once shipped — clients and stored translations reference it.')
            ->setDisabled($pageName === Crud::PAGE_EDIT);

        yield TextField::new('name')
            ->setHelp('Display name, e.g. "English".');

        yield BooleanField::new('isEnabled')
            ->setHelp('Disabled languages are hidden from /api/languages and admin language pickers. Existing translations are kept, not deleted.');

        yield BooleanField::new('isDefault')
            ->setHelp('The fallback language for narrative content and untranslated generic strings. Enabling this on one language automatically disables it on every other — there is always exactly one default.');

        yield IntegerField::new('sortOrder')
            ->setHelp('Display order in /api/languages and admin pickers, ascending.');

        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Language) {
            $this->enforceSingleDefault($entityInstance);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Language) {
            $this->enforceSingleDefault($entityInstance);
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function enforceSingleDefault(Language $language): void
    {
        if (!$language->isDefault()) {
            return;
        }

        // A default language must be reachable, or every narrative fallback breaks.
        $language->setIsEnabled(true);

        foreach ($this->languageRepository->findAll() as $other) {
            if ($other !== $language && $other->isDefault()) {
                $other->setIsDefault(false);
            }
        }
    }

    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof Language && $entityInstance->isDefault()) {
            $this->addFlash('danger', 'Cannot delete the default language — set another language as default first.');
            return;
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }
}

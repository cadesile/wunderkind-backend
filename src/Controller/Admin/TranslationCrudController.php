<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Translation;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * One (TranslationKey, Language) value. Generic (UI-copy) keys only — narrative-linked
 * translations are managed via each entity's own "Translations" action instead (see
 * NarrativeTranslationController), not this screen.
 */
class TranslationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Translation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Translation')
            ->setEntityLabelInPlural('Translations')
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(EntityFilter::new('language'));
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->leftJoin('entity.translationKey', 'tk')
            ->andWhere('tk.entityType IS NULL');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield AssociationField::new('translationKey', 'Key')
            ->setQueryBuilder(fn (QueryBuilder $qb) => $qb->andWhere(sprintf('%s.entityType IS NULL', $qb->getRootAliases()[0])))
            ->autocomplete();

        yield AssociationField::new('language')
            ->autocomplete();

        yield TextareaField::new('value');

        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TranslationKey;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Generic (UI-copy) translation keys only — narrative-linked keys (GameEventTemplate/
 * FacilityTemplate/Excursion text) are intentionally excluded here and managed only via
 * each entity's own "Translations" action (see NarrativeTranslationController), not this
 * screen.
 */
class TranslationKeyCrudController extends AbstractCrudController
{
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
            ->setHelp('index', 'Generic UI-copy keys only. Event/facility/excursion text is translated from that entity\'s own "Translations" action instead.');
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
        yield BooleanField::new('isPluralSensitive')
            ->setHelp('Whether the client\'s rendering engine must apply plural-sensitive handling to this key. Structural, not a translated value.');
        yield CodeEditorField::new('bandedReferencesJson', 'Banded References')
            ->setLanguage('js')
            ->setNumOfRows(8)
            ->setHelp('Fragment-name => other-key-name map describing how the client composes this key\'s text from other keys\' sub-fragments. Leave empty (or {}) for none.')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}

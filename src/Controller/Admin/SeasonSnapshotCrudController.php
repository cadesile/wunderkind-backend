<?php

namespace App\Controller\Admin;

use App\Entity\SeasonSnapshot;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Read-only view of the full squad/finance snapshot written by
 * LeagueService::concludeSeason() on POST /api/league/conclude-season.
 */
class SeasonSnapshotCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SeasonSnapshot::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('club');
        yield IntegerField::new('season');
        yield TextField::new('country');
        yield DateTimeField::new('createdAt')->setFormat('yyyy-MM-dd HH:mm');
        yield CodeEditorField::new('snapshotData', 'Snapshot Data')
            ->setLanguage('js')
            ->onlyOnDetail()
            ->formatValue(fn (array $value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\SeasonRecord;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/**
 * Read-only view of the per-club, per-season league table row written by
 * LeagueService::concludeSeason() on POST /api/league/conclude-season.
 */
class SeasonRecordCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SeasonRecord::class;
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
        yield AssociationField::new('league');
        yield IntegerField::new('season');
        yield IntegerField::new('finalPosition');
        yield IntegerField::new('gamesPlayed');
        yield IntegerField::new('wins');
        yield IntegerField::new('draws');
        yield IntegerField::new('losses');
        yield IntegerField::new('goalsFor');
        yield IntegerField::new('goalsAgainst');
        yield IntegerField::new('points');
        yield BooleanField::new('promoted');
        yield BooleanField::new('relegated');
        yield DateTimeField::new('createdAt')->setFormat('yyyy-MM-dd HH:mm');
    }
}

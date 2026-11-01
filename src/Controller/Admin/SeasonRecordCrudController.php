<?php

namespace App\Controller\Admin;

use App\Entity\SeasonRecord;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/**
 * Read-only view of the per-club, per-season league table row written by
 * LeagueService::concludeSeason() on POST /api/league/conclude-season.
 *
 * A row can be corrected by a later resubmission, so `overwriteCount` and the
 * `overwriteAudit` on the detail page are the record of what it used to say. That audit is
 * the only place a superseded result survives — nothing derived from this row is recomputed
 * on a correction, so a dispute about a Hall of Fame title is settled from it by hand.
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

        // How many times a later resubmission corrected this season's result, and what it
        // replaced. The audit is read through the entity's pretty-printing accessor because
        // EasyAdmin's code editor needs a string rather than a json array column; detail
        // only, since it is noise on an index of every season ever concluded.
        yield IntegerField::new('overwriteCount', 'Overwrites');
        yield CodeEditorField::new('overwriteAuditPretty', 'Overwrite audit')
            ->setLanguage('js')
            ->onlyOnDetail();
    }
}

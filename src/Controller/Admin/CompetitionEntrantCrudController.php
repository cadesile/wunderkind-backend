<?php

namespace App\Controller\Admin;

use App\Entity\Competition\CompetitionEntrant;
use App\Enum\Competition\CompetitionEntrantStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * Read-only view of every club snapshot submitted at registration/resubmission — the
 * primary use is debugging/support: confirming what a club actually sent (roster size,
 * tactics, attribute values) without needing direct DB access. Entrants are created by
 * CompetitionRegistrationService and updated by round processing/resubmission, never
 * hand-edited, so every action is disabled — same reasoning as
 * ActiveCompetitionCrudController/CompetitionRoundCrudController.
 *
 * The "Generate Spoof Entrants" admin test-data tool lives on ActiveCompetitionCrudController
 * (the competition row/detail page), not here — spoofing fills out a competition, so the
 * trigger belongs at the competition level even though it clones an entrant's snapshot
 * under the hood (see CompetitionSpoofEntrantService::generateSpoofEntrantsForCompetition()).
 * `club.isSpoof` is still surfaced here so spoof-generated entrants stay identifiable from
 * this debugging view.
 */
class CompetitionEntrantCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CompetitionEntrant::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['registeredAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // EDIT is disabled, so EasyAdmin's default row-click fallback ([EDIT, DETAIL])
        // needs DETAIL explicitly present in the index actions to click through to it.
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('activeCompetition', 'Competition');
        yield AssociationField::new('club');
        yield BooleanField::new('club.isSpoof', 'Spoof')->renderAsSwitch(false);
        yield IntegerField::new('seed');

        yield ChoiceField::new('status')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => CompetitionEntrantStatus::class]);

        // Snapshot-derived summary columns — scan the list without opening every row.
        yield IntegerField::new('snapshotPlayerCount', 'Players');
        yield TextField::new('snapshotFormation', 'Formation')->hideOnIndex();
        yield TextField::new('snapshotPlayingStyle', 'Playing Style');

        yield IntegerField::new('snapshotVersion', 'Version')->hideOnIndex();
        yield DateTimeField::new('snapshotLockedAt')->hideOnIndex();
        yield DateTimeField::new('registeredAt');
        yield AssociationField::new('eliminatedInRound')->hideOnIndex();

        yield CodeEditorField::new('snapshotJsonPretty', 'Full Snapshot')
            ->setLanguage('js')
            ->setNumOfRows(24)
            ->onlyOnDetail();
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\Competition\ActiveCompetition;
use App\Enum\Competition\ActiveCompetitionStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * Read-only "monitor active running competitions" view. Every action is disabled
 * deliberately — instances are created by the provisioning cron and progressed by the
 * round processor, never hand-edited.
 */
class ActiveCompetitionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ActiveCompetition::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('template');

        yield ChoiceField::new('status')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => ActiveCompetitionStatus::class]);

        yield IntegerField::new('entrantCapacity');
        yield TextField::new('durationOption');

        yield DateTimeField::new('registrationOpenedAt');
        yield DateTimeField::new('lockedAt')->hideOnIndex();
        yield DateTimeField::new('startsAt');
        yield DateTimeField::new('endsAt');
        yield DateTimeField::new('completedAt');
        yield DateTimeField::new('cancelledAt')->hideOnIndex();
        yield TextField::new('cancellationReason')->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}

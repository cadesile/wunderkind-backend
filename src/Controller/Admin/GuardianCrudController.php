<?php

namespace App\Controller\Admin;

use App\Entity\Guardian;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class GuardianCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Guardian::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Guardian')
            ->setEntityLabelInPlural('Guardians')
            ->setDefaultSort(['lastName' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('firstName', 'First Name');
        yield TextField::new('lastName', 'Last Name');
        yield ChoiceField::new('gender', 'Gender')
            ->setChoices(['Male' => 'male', 'Female' => 'female']);
        yield IntegerField::new('demandLevel', 'Demand Level')
            ->setHelp('1 (relaxed) to 10 (very demanding)');
        yield IntegerField::new('loyaltyToAcademy', 'Loyalty to Academy')
            ->setHelp('0–100');
        yield AssociationField::new('player', 'Player')->hideOnForm();
        yield TextField::new('contactEmail', 'Contact Email')->onlyOnForms();
    }
}

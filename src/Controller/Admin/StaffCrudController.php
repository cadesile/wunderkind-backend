<?php

namespace App\Controller\Admin;

use App\Entity\Staff;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class StaffCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Staff::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['lastName' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('firstName');
        yield TextField::new('lastName');
        yield TextField::new('roleValue', 'Role');
        yield IntegerField::new('coachingAbility');
        yield IntegerField::new('scoutingRange');
        yield AssociationField::new('academy');
    }
}

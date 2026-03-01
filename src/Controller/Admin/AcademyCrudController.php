<?php

namespace App\Controller\Admin;

use App\Entity\Academy;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class AcademyCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Academy::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield AssociationField::new('user');
        yield IntegerField::new('reputation');
        yield IntegerField::new('totalCareerEarnings');
        yield IntegerField::new('hallOfFamePoints');
        yield IntegerField::new('lastSyncedWeek');
        yield DateTimeField::new('lastSyncedAt')->setFormat('yyyy-MM-dd HH:mm')->setRequired(false);
        yield DateTimeField::new('createdAt')->setFormat('yyyy-MM-dd HH:mm');
    }
}

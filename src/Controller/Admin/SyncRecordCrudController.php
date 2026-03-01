<?php

namespace App\Controller\Admin;

use App\Entity\SyncRecord;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SyncRecordCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SyncRecord::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['serverTimestamp' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('academy');
        yield IntegerField::new('clientWeekNumber');
        yield BooleanField::new('isValid');
        yield TextField::new('invalidReason')->setRequired(false);
        yield DateTimeField::new('clientTimestamp')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('serverTimestamp')->setFormat('yyyy-MM-dd HH:mm');
    }
}

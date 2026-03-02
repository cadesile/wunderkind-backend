<?php

namespace App\Controller\Admin;

use App\Entity\LeaderboardEntry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class LeaderboardEntryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LeaderboardEntry::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['score' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('academy');
        yield TextField::new('categoryValue', 'Category');
        yield TextField::new('period');
        yield IntegerField::new('score');
        yield IntegerField::new('rank')->setRequired(false);
        yield DateTimeField::new('updatedAt')->setFormat('yyyy-MM-dd HH:mm');
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\Scout;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ScoutCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Scout::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield DateField::new('dob')->setLabel('Date of Birth');
        yield TextField::new('nationality');
        yield IntegerField::new('experience');
        yield TextField::new('judgements')
            ->formatValue(fn($v) => is_array($v) ? json_encode($v) : ($v ?? ''))
            ->hideOnForm()
            ->hideOnIndex();
    }
}

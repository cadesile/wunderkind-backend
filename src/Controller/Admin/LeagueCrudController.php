<?php

namespace App\Controller\Admin;

use App\Entity\League;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class LeagueCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return League::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['country' => 'ASC', 'tier' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('country')
            ->setHelp('ISO 2-letter code, e.g. ES, EN, DE');
        yield IntegerField::new('tier')
            ->setHelp('1 (top) to 8 (bottom)');
        yield TextField::new('name')
            ->setHelp('Default "League {tier}", overridable e.g. "Premier League"');
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\FacilityTemplate;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class FacilityTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return FacilityTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['sortOrder' => 'ASC', 'slug' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('slug')
            ->setHelp('Unique snake_case identifier matching the frontend type key, e.g. technical_zone');
        yield TextField::new('label')
            ->setHelp('Display name shown in the app');
        yield TextareaField::new('description')
            ->setHelp('Flavour text shown on the facility card')
            ->hideOnIndex();
        yield ChoiceField::new('category')
            ->setChoices(['Training' => 'TRAINING', 'Medical' => 'MEDICAL', 'Scouting' => 'SCOUTING']);
        yield IntegerField::new('baseCost')
            ->setHelp('Upgrade cost base in pence. App formula: (currentLevel + 1) × baseCost');
        yield IntegerField::new('weeklyUpkeepBase')
            ->setHelp('Weekly upkeep in pence at level 1. App formula: base × 1.5^level')
            ->hideOnIndex();
        yield NumberField::new('reputationBonus')
            ->setHelp('Reputation awarded to the academy per upgrade level')
            ->setNumDecimals(2)
            ->hideOnIndex();
        yield IntegerField::new('maxLevel')
            ->setHelp('Maximum upgrade level (default 5)');
        yield NumberField::new('decayBase')
            ->setHelp('Weekly condition decay base. App formula: decayBase + level')
            ->setNumDecimals(1)
            ->hideOnIndex();
        yield IntegerField::new('sortOrder')
            ->setHelp('Display order in the facilities screen (ascending)');
        yield BooleanField::new('isActive')
            ->setHelp('Inactive facilities are hidden from all clients on next sync');
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}

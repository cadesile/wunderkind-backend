<?php

namespace App\Controller\Admin;

use App\Entity\StarterConfig;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class StarterConfigCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return StarterConfig::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // Single-row entity — creating or deleting rows is not permitted.
        return $actions->disable(Action::NEW, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setPageTitle(Crud::PAGE_INDEX, 'Starter Config')
                    ->setPageTitle(Crud::PAGE_EDIT, 'Edit Starter Config')
                    ->setHelp(Crud::PAGE_EDIT, 'These values define the starting conditions for every new academy. Changes take effect on the next new registration.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('startingBalance')
            ->setHelp('In pence. £50,000 = 5,000,000');
        yield IntegerField::new('starterPlayerCount')
            ->setHelp('Players assigned at academy creation. Default: 5');
        yield IntegerField::new('starterCoachCount')
            ->setHelp('Coaches assigned at academy creation. Default: 1');
        yield IntegerField::new('starterScoutCount')
            ->setHelp('Scouts assigned at academy creation. Default: 1');
        yield TextField::new('starterSponsorTier')
            ->setHelp('Sponsor company-size tier: SMALL, MEDIUM, or LARGE');
    }
}

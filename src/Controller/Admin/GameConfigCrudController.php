<?php

namespace App\Controller\Admin;

use App\Entity\GameConfig;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;

class GameConfigCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameConfig::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // Single-row entity — creating or deleting rows is not permitted.
        return $actions->disable(Action::NEW, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setPageTitle(Crud::PAGE_INDEX, 'Game Config')
                    ->setPageTitle(Crud::PAGE_EDIT, 'Edit Game Config')
                    ->setHelp(Crud::PAGE_EDIT, 'Runtime engine constants synced to every client on each /api/sync response. Changes take effect on the next sync.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('cliqueRelationshipThreshold')
            ->setHelp('Min pairwise relationship value (−100 to +100) for two players to be clique-eligible. Default: 20');
        yield IntegerField::new('cliqueSquadCapPercent')
            ->setHelp('Max % of the active squad that can be in cliques combined. Default: 30');
        yield IntegerField::new('cliqueMinTenureWeeks')
            ->setHelp('Min weeks at academy before a player can form or join a clique. Default: 3');
        yield IntegerField::new('baseXP')
            ->setHelp('Base XP awarded per player per week before facility/coach multipliers. Default: 10');
        yield NumberField::new('baseInjuryProbability')
            ->setNumDecimals(4)
            ->setHelp('Base probability of injury per player per week (fraction). Default: 0.05 = 5%');
        yield IntegerField::new('regressionUpperThreshold')
            ->setHelp('Trait value (1–20 scale) above which regression-to-mean pushes down. Default: 14');
        yield IntegerField::new('regressionLowerThreshold')
            ->setHelp('Trait value (1–20 scale) below which regression-to-mean pushes up. Default: 7');
        yield NumberField::new('reputationDeltaBase')
            ->setNumDecimals(2)
            ->setHelp('Base reputation delta per week before facility multiplier. Default: 0.5');
        yield NumberField::new('reputationDeltaFacilityMultiplier')
            ->setNumDecimals(2)
            ->setHelp('Per-level facility multiplier applied to reputation delta. Default: 1.2');
        yield IntegerField::new('injuryMinorWeight')
            ->setHelp('Relative weight for minor injury severity. Default: 60');
        yield IntegerField::new('injuryModerateWeight')
            ->setHelp('Relative weight for moderate injury severity. Default: 30');
        yield IntegerField::new('injurySeriousWeight')
            ->setHelp('Relative weight for serious injury severity. Default: 10');
    }
}

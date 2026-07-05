<?php

namespace App\Controller\Admin;

use App\Entity\SocialPostTemplate;
use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class SocialPostTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SocialPostTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['category' => 'ASC', 'platform' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield ChoiceField::new('category')
            ->setChoices(array_combine(
                array_map(fn (StatCategory $c) => ucfirst(str_replace('_', ' ', $c->value)), StatCategory::cases()),
                StatCategory::cases(),
            ));
        yield ChoiceField::new('platform')
            ->setChoices(array_combine(
                array_map(fn (SocialPlatform $p) => ucfirst($p->value), SocialPlatform::cases()),
                SocialPlatform::cases(),
            ));
        yield ChoiceField::new('period')
            ->setChoices(array_combine(
                array_map(fn (StatsPeriod $p) => ucfirst($p->value), StatsPeriod::cases()),
                StatsPeriod::cases(),
            ))
            ->setHelp('Which CommunityStatsService period this template pulls data from.');
        yield TextareaField::new('bodyTemplate')
            ->setHelp('Use {{clubName}}, {{value}}, {{rank}}, {{period}}, {{categoryLabel}} as placeholders. X posts must stay ≤ 280 characters after substitution — Facebook has no limit.')
            ->hideOnIndex();
        yield BooleanField::new('isActive')->renderAsSwitch(true);
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}

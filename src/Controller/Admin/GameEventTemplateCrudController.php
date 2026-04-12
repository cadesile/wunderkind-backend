<?php

namespace App\Controller\Admin;

use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use App\Form\Type\ChainLinkType;
use App\Form\Type\EventImpactsType;
use App\Form\Type\FiringConditionsType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class GameEventTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameEventTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['category' => 'ASC', 'weight' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('slug')
            ->setHelp('Unique snake_case identifier, e.g. player_homesick');
        yield ChoiceField::new('category')
            ->setChoices(array_combine(
                array_map(fn (EventCategory $c) => ucfirst($c->value), EventCategory::cases()),
                EventCategory::cases(),
            ))
            ->formatValue(fn ($v) => $v instanceof EventCategory ? ucfirst($v->value) : ucfirst((string) $v));
        yield IntegerField::new('weight')
            ->setHelp('0 = inactive (never randomly selected). Higher = more frequent.');
        yield TextField::new('title');
        yield TextareaField::new('bodyTemplate')
            ->setHelp('Use {player}, {staff}, {facility}, {amount} as placeholders.')
            ->hideOnIndex();
        yield Field::new('impacts', 'Impacts')
            ->setFormType(EventImpactsType::class)
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Configure all stat changes, relationships, choices, and duration for this event.');
        yield ChoiceField::new('severity')
            ->setChoices(['Minor' => 'minor', 'Major' => 'major'])
            ->setRequired(false)
            ->setHelp('minor = read-only inbox report. major = AMP must respond.');
        yield Field::new('firingConditions', 'Firing Conditions')
            ->setFormType(FiringConditionsType::class)
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Leave blank for events with no firing conditions.');
        yield CollectionField::new('chainedEventsArray', 'Chained Events')
            ->setEntryType(ChainLinkType::class)
            ->allowAdd()
            ->allowDelete()
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Each entry boosts a follow-up event\'s weight for the same player pair after this event fires.');
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}

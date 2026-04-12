<?php

namespace App\Controller\Admin;

use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use App\Form\Type\ChainLinkType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
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
        yield TextareaField::new('impactsJson', 'Impacts (JSON)')
            ->setHelp(
                'Array of impact descriptors. Example: [{"target":"player.morale","delta":-10}]. ' .
                'target: player.morale, player.confidence, player.energy, academy.reputation, academy.finances, staff.morale.'
            )
            ->hideOnIndex()
            ->setNumOfRows(6)
            ->setRequired(false);
        yield ChoiceField::new('severity')
            ->setChoices(['Minor' => 'minor', 'Major' => 'major'])
            ->setRequired(false)
            ->setHelp('minor = read-only inbox report. major = AMP must respond.');
        yield TextareaField::new('firingConditionsJson', 'Firing Conditions (JSON)')
            ->setRequired(false)
            ->setHelp('JSON: maxSquadMorale, maxPairRelationship, requiresCoLocation, actorTraitRequirements, subjectTraitRequirements')
            ->hideOnIndex();
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

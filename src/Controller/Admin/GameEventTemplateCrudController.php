<?php

namespace App\Controller\Admin;

use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
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
                EventCategory::cases(),  // pass enum cases so EasyAdmin compares correctly
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
                'Array of impact descriptors. Example: [{"target":"player.morale","delta":-10},{"target":"academy.reputation","delta":5}]. ' .
                'target can be: player.morale, player.confidence, player.energy, academy.reputation, academy.finances, staff.morale.'
            )
            ->hideOnIndex()
            ->setNumOfRows(6)
            ->setRequired(false);
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}

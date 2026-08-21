<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PlayerArchetype;
use App\Enum\ArchetypePolarity;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class PlayerArchetypeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PlayerArchetype::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['polarity' => 'ASC', 'slug' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('slug')
            ->setHelp('Stable machine identity shared with the client, e.g. "standard_bearer". Changing this breaks client mapping.');
        yield TextField::new('name')
            ->setHelp('Display name shown to the player in-game, e.g. "Standard Bearer".');
        yield ChoiceField::new('polarity')
            ->setChoices(ArchetypePolarity::cases())
            ->setHelp('Positive archetypes and negative archetypes are resolved independently — each player gets one of each.');
        yield TextareaField::new('description')
            ->setHelp('Flavour text explaining the archetype\'s personality.')
            ->hideOnIndex();

        // traitWeights is a structured JSON object — display as formatted text,
        // edit via app:seed-archetypes or direct DB update to avoid form type conflicts.
        yield TextareaField::new('traitWeightsJson', 'Trait Weights (JSON)')
            ->setHelp(
                'Schema: {"formula":{"professionalism":0.5,"determination":0.5},"threshold":65} — ' .
                'Traits (the only valid keys): determination, professionalism, ambition, loyalty, ' .
                'adaptability, pressure, temperament, consistency. ' .
                'Weights are SIGNED: positive = "High trait", negative = "Low trait". ' .
                'Absolute values must sum to 1.0. Traits are stored 1–20 and normalised to 0–100 ' .
                'by the client before comparison against threshold.'
            )
            ->hideOnIndex()
            ->setNumOfRows(8)
            ->setRequired(true);

        yield DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PlayerArchetype;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
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
        return $crud->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name')
            ->setHelp('Display name shown to the player in-game, e.g. "The Captain".');
        yield TextareaField::new('description')
            ->setHelp('Flavour text explaining the archetype\'s personality.')
            ->hideOnIndex();

        // traitMapping is a structured JSON object — display as formatted text,
        // edit via app:seed-archetypes or direct DB update to avoid form type conflicts.
        yield TextareaField::new('traitMapping', 'Trait Mapping (JSON)')
            ->formatValue(fn ($v) => is_array($v) ? json_encode($v, JSON_PRETTY_PRINT) : $v)
            ->setHelp(
                'Schema: {"formula":{"bravery":0.4,"consistency":0.3,"loyalty":0.3},"threshold":70}. ' .
                'Traits: bravery, consistency, loyalty, professionalism, ambition, ego, confidence, pressure. ' .
                'Weights must sum to 1.0. Threshold = minimum weighted score (0–100) to match.'
            )
            ->hideOnIndex()
            ->hideOnForm();

        yield DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\Competition\RewardTemplate;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Standalone, reusable reward definitions — attachable to one or more
 * CompetitionTemplates, and usable by future in-game milestones (e.g. promotions).
 */
class RewardTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return RewardTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('slug')->setHelp('Unique. Used as the reward-claim trigger context.');
        yield TextField::new('name');
        yield TextareaField::new('description')->hideOnIndex();

        yield CodeEditorField::new('effectsJson', 'Effects')
            ->setLanguage('js')
            ->setNumOfRows(10)
            ->setHelp($this->effectsHelp())
            ->hideOnIndex();

        yield BooleanField::new('isActive')->renderAsSwitch(true);
    }

    private function effectsHelp(): string
    {
        return <<<'HTML'
            JSON array of GameEffect objects. Three types:
            <ul>
                <li><code>{"type": "ledger_delta", "amountPence": 500000}</code> — credits the club's balance (pence).</li>
                <li><code>{"type": "reputation_delta", "amount": 10}</code> — adjusts reputation (floors at 0).</li>
                <li><code>{"type": "unique_asset_grant", "assetType": "youth_player", "payload": {...}}</code> —
                    <strong>Phase 1 stub</strong>: recorded but not yet delivered (no delivery mechanism exists
                    for minting an asset outside the pool lifecycle).</li>
            </ul>
            Delivered to the winning club as an inbox message — never applied directly; the player must accept it.
            HTML;
    }
}

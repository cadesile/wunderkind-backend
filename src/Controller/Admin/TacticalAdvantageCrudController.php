<?php

namespace App\Controller\Admin;

use App\Entity\TacticalAdvantage;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use App\Enum\PlayingStyle;

class TacticalAdvantageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TacticalAdvantage::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            ChoiceField::new('style')->setChoices([
                'POSSESSION' => PlayingStyle::POSSESSION,
                'DIRECT'     => PlayingStyle::DIRECT,
                'COUNTER'    => PlayingStyle::COUNTER,
                'HIGH_PRESS' => PlayingStyle::HIGH_PRESS,
            ]),
            ChoiceField::new('opponentStyle')->setChoices([
                'POSSESSION' => PlayingStyle::POSSESSION,
                'DIRECT'     => PlayingStyle::DIRECT,
                'COUNTER'    => PlayingStyle::COUNTER,
                'HIGH_PRESS' => PlayingStyle::HIGH_PRESS,
            ]),
            NumberField::new('multiplier')->setHelp('e.g. 1.15 for 15% boost'),
        ];
    }
}

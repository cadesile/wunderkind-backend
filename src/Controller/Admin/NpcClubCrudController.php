<?php

namespace App\Controller\Admin;

use App\Entity\NpcClub;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;

class NpcClubCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NpcClub::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield TextField::new('abbreviation')
            ->setHelp('Up to 5 chars, e.g. MAN, BARCA')
            ->hideOnIndex();
        yield TextField::new('country')
            ->setHelp('ISO 2-letter code, e.g. ES, EN, DE');
        yield IntegerField::new('tier')
            ->setHelp('1 (top) to 8 (bottom)');
        yield IntegerField::new('reputation')
            ->setHelp('0–100');
        yield IntegerField::new('balance', 'Starting Balance')
            ->setFormType(MoneyType::class)
            ->setFormTypeOptions(['currency' => 'GBP', 'divisor' => 100])
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—')
            ->setHelp('Starting budget in pounds.');
        yield ColorField::new('primaryColor')->hideOnIndex();
        yield ColorField::new('secondaryColor')->hideOnIndex();
        yield TextField::new('stadiumName')
            ->setHelp('Optional stadium name, e.g. Estadio El Cid')
            ->hideOnIndex();
        yield TextareaField::new('facilitiesJson', 'Facilities')
            ->setHelp('JSON: {"training_pitch": 6, "north_stand": 4, ...}')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}

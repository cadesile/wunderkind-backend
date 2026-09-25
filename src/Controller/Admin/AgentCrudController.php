<?php

namespace App\Controller\Admin;

use App\Entity\Agent;
use App\Form\Type\AppearanceType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class AgentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Agent::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // NEW stays disabled — Agent::__construct(string $name) requires an
        // argument EasyAdmin's default createEntity() can't supply.
        return $actions->disable(Action::NEW, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['name' => 'ASC'])
            ->addFormTheme('admin/form/appearance_theme.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield NumberField::new('commissionRate')->setNumDecimals(2);
        yield NumberField::new('reputation');
        yield DateField::new('dob')->setLabel('Date of Birth');
        yield TextField::new('nationality');
        yield IntegerField::new('experience');
        yield IntegerField::new('rating');

        // ── Panel: Appearance ─────────────────────────────────────────────────
        yield FormField::addFieldset('Appearance', 'fa fa-user-circle')->hideOnIndex();

        yield Field::new('appearance')
            ->setFormType(AppearanceType::class)
            ->setFormTypeOptions(['person_type' => 'staff'])
            ->onlyOnForms();
    }
}

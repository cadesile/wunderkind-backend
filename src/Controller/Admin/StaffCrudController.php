<?php

namespace App\Controller\Admin;

use App\Entity\Staff;
use App\Enum\StaffRole;
use App\Form\Type\AppearanceType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class StaffCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Staff::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['lastName' => 'ASC'])
            ->addFormTheme('admin/form/appearance_theme.html.twig');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('role')->setChoices([
                'Coach'                => StaffRole::COACH,
                'Manager'              => StaffRole::MANAGER,
                'Director of Football' => StaffRole::DIRECTOR_OF_FOOTBALL,
                'Facility Manager'     => StaffRole::FACILITY_MANAGER,
                'Chairman'             => StaffRole::CHAIRMAN,
            ]))
            ->add(TextFilter::new('nationality'))
            ->add(NumericFilter::new('coachingAbility'))
            ->add(NumericFilter::new('scoutingRange'))
            ->add(NumericFilter::new('morale'));
    }

    public function configureFields(string $pageName): iterable
    {
        // Index: firstName | lastName | nationality | role | DOB | coachingAbility
        yield TextField::new('firstName');
        yield TextField::new('lastName');
        yield TextField::new('nationality');

        yield ChoiceField::new('role')
            ->setChoices([
                'Coach'                => StaffRole::COACH,
                'Manager'              => StaffRole::MANAGER,
                'Director of Football' => StaffRole::DIRECTOR_OF_FOOTBALL,
                'Facility Manager'     => StaffRole::FACILITY_MANAGER,
                'Chairman'             => StaffRole::CHAIRMAN,
            ])
            ->renderAsBadges([
                StaffRole::COACH->value                => 'warning',
                StaffRole::MANAGER->value              => 'dark',
                StaffRole::DIRECTOR_OF_FOOTBALL->value => 'secondary',
                StaffRole::FACILITY_MANAGER->value     => 'light',
                StaffRole::CHAIRMAN->value             => 'danger',
            ]);

        yield \EasyCorp\Bundle\EasyAdminBundle\Field\DateField::new('dob', 'DOB');

        yield IntegerField::new('coachingAbility')->setHelp('1–100');

        // Detail / form only
        yield IdField::new('id')->onlyOnDetail();
        yield IntegerField::new('scoutingRange')->setHelp('1–100')->hideOnIndex();
        yield IntegerField::new('morale')->setHelp('0–100')->hideOnIndex();

        yield TextField::new('specialty')->hideOnIndex();

        yield TextField::new('specialisms', 'Specialisms')
            ->formatValue(function ($v) {
                if (!is_array($v) || empty($v)) return '—';
                return implode(', ', array_map(
                    fn($k, $val) => ucfirst($k) . ': ' . $val,
                    array_keys($v), $v
                ));
            })
            ->hideOnIndex()
            ->hideOnForm();

        yield \EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField::new('specialismsJson', 'Specialisms (JSON)')
            ->setHelp(
                'Keys: pace, technical, vision, power, stamina, heart. Values 50–90. ' .
                'Example: {"pace":85,"technical":70}. Leave as {} to clear.'
            )
            ->hideOnIndex()
            ->setNumOfRows(4)
            ->onlyOnForms();

        yield IntegerField::new('weeklySalary', 'Weekly Salary')
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) . ' / wk' : '—')
            ->setHelp('Weekly salary in pence — £1,000 = 100,000')
            ->hideOnIndex();

        yield DateTimeField::new('hiredAt')->hideOnForm()->hideOnIndex();

        // ── Panel: Personality ────────────────────────────────────────────────
        yield FormField::addFieldset('Personality', 'fa fa-brain')->hideOnIndex();

        yield IntegerField::new('personality.determination', 'Determination')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.professionalism', 'Professionalism')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.ambition', 'Ambition')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.loyalty', 'Loyalty')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.adaptability', 'Adaptability')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.pressure', 'Pressure')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.temperament', 'Temperament')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.consistency', 'Consistency')->setHelp('1–20')->setColumns(3)->hideOnIndex();

        // ── Panel: Appearance ─────────────────────────────────────────────────
        yield FormField::addFieldset('Appearance', 'fa fa-user-circle')->hideOnIndex();

        yield Field::new('appearance')
            ->setFormType(AppearanceType::class)
            ->onlyOnForms();
    }
}

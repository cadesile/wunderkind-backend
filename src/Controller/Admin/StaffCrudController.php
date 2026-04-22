<?php

namespace App\Controller\Admin;

use App\Entity\Staff;
use App\Enum\StaffRole;
use App\Repository\ClubRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class StaffCrudController extends AbstractCrudController
{
    public function __construct(private readonly ClubRepository $clubRepository) {}

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
        return $crud->setDefaultSort(['lastName' => 'ASC']);
    }

    public function createEntity(string $entityFqcn): Staff
    {
        $club = $this->clubRepository->findOneBy([]);

        if ($club === null) {
            throw new \RuntimeException('No Club exists yet. Register a user first.');
        }

        return new Staff(
            firstName: '',
            lastName: '',
            role: StaffRole::COACH,
            club: $club,
        );
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
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

        yield IntegerField::new('coachingAbility')->setHelp('1–100');
        yield IntegerField::new('scoutingRange')->setHelp('1–100');
        yield IntegerField::new('morale')->setHelp('0–100');

        yield \EasyCorp\Bundle\EasyAdminBundle\Field\DateField::new('dob', 'Date of Birth')
            ->hideOnForm();

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

        yield AssociationField::new('club');

        yield DateTimeField::new('hiredAt')->hideOnForm();
    }
}

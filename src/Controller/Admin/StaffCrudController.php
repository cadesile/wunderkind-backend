<?php

namespace App\Controller\Admin;

use App\Entity\Staff;
use App\Enum\StaffRole;
use App\Repository\AcademyRepository;
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
    public function __construct(private readonly AcademyRepository $academyRepository) {}

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

    /**
     * Staff constructor requires role + academy — supply defaults so EasyAdmin
     * can instantiate the form before the user fills in the real values.
     */
    public function createEntity(string $entityFqcn): Staff
    {
        $academy = $this->academyRepository->findOneBy([]);

        if ($academy === null) {
            throw new \RuntimeException('No Academy exists yet. Register a user first.');
        }

        return new Staff(
            firstName: '',
            lastName: '',
            role: StaffRole::ASSISTANT_COACH,
            academy: $academy,
        );
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('firstName');
        yield TextField::new('lastName');

        yield ChoiceField::new('role')
            ->setChoices([
                'Head Coach'       => StaffRole::HEAD_COACH,
                'Assistant Coach'  => StaffRole::ASSISTANT_COACH,
                'Scout'            => StaffRole::SCOUT,
                'Fitness Coach'    => StaffRole::FITNESS_COACH,
                'Analyst'          => StaffRole::ANALYST,
            ])
            ->renderAsBadges([
                StaffRole::HEAD_COACH->value      => 'danger',
                StaffRole::ASSISTANT_COACH->value => 'warning',
                StaffRole::SCOUT->value           => 'info',
                StaffRole::FITNESS_COACH->value   => 'success',
                StaffRole::ANALYST->value         => 'primary',
            ]);

        yield IntegerField::new('coachingAbility')->setHelp('1–100');
        yield IntegerField::new('scoutingRange')->setHelp('1–100');

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

        yield AssociationField::new('academy');

        yield DateTimeField::new('hiredAt')->hideOnForm();
    }
}

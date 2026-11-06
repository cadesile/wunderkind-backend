<?php

namespace App\Controller\Admin;

use App\Admin\FacetCounter;
use App\Entity\Staff;
use App\Enum\Country;
use App\Filter\Admin\PenceRangeFilter;
use App\Twig\BmcAdminExtension;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use App\Entity\PersonalityProfile;
use App\Enum\StaffRole;
use App\Form\Type\AppearanceType;
use App\Form\Type\ArchetypeSummaryType;
use App\Service\ArchetypeResolverService;
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
    public function __construct(
        private readonly ArchetypeResolverService $archetypeResolver,
        private readonly FacetCounter $facets,
        private readonly BmcAdminExtension $bmc,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Staff::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('View staff')->setIcon('eye')
                ->setHtmlAttributes(['title' => 'View staff', 'aria-label' => 'View staff member'])
                ->setCssClass('bmc-iconbtn'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a
                ->setLabel('Edit staff')->setIcon('pencil')
                ->setHtmlAttributes(['title' => 'Edit staff', 'aria-label' => 'Edit staff member'])
                ->setCssClass('bmc-iconbtn'));
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Staff member')
            ->setEntityLabelInPlural('Staff')
            ->setDefaultSort(['lastName' => 'ASC'])
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['firstName', 'lastName', 'nationality'])
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig')
            ->addFormTheme('admin/form/appearance_theme.html.twig')
            ->addFormTheme('admin/form/archetype_summary_theme.html.twig');
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
            ])->canSelectMultiple())
            ->add(ChoiceFilter::new('nationality', 'Nationality')->setChoices($this->nationalityChoices())->canSelectMultiple())
            ->add(NumericFilter::new('coachingAbility', 'Coaching ability'))
            ->add(NumericFilter::new('morale', 'Morale'))
            ->add(PenceRangeFilter::new('weeklySalary', 'Salary/wk'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'People & market');
        $responseParameters->set('bmcDomain', 'people');
        $responseParameters->set('bmcTotal', $this->em->getRepository(Staff::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Staff name');
        $responseParameters->set('bmcUnits', ['weeklySalary' => '£']);
        $responseParameters->set('bmcWidths', ['role' => 172, 'coachingAbility' => 160, 'weeklySalary' => 100]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            ['role' => 'entity.role', 'nationality' => 'entity.nationality'],
        ));

        return $responseParameters;
    }

    /** @return array<string, string> only the nationalities actually present */
    private function nationalityChoices(): array
    {
        $rows = $this->em->createQuery(
            'SELECT DISTINCT s.nationality FROM ' . Staff::class . ' s WHERE s.nationality IS NOT NULL ORDER BY s.nationality ASC'
        )->getScalarResult();

        return array_combine(array_column($rows, 'nationality'), array_column($rows, 'nationality'));
    }

    /** Staff member, role, coaching ability, salary. */
    private function indexFields(): iterable
    {
        yield TextField::new('lastName', 'Staff')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Staff $s) => [
                'flag' => $s->getNationality() ? Country::fromNationality($s->getNationality())?->value : null,
                'text' => $s->getFullName(),
                'sub'  => $s->getNationality(),
            ]);

        yield ChoiceField::new('role', 'Role')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Staff $s) => ['chip' => match ($s->getRoleValue()) {
                'manager'              => ['label' => 'MANAGER', 'color' => 'positive'],
                'chairman'             => ['label' => 'CHAIRMAN', 'color' => 'warning'],
                'facility_manager'     => ['label' => 'FACILITY MGR', 'color' => 'subtle'],
                'director_of_football' => ['label' => 'DIR. OF FOOTBALL', 'color' => null],
                default                => ['label' => strtoupper(str_replace('_', ' ', $s->getRoleValue())), 'color' => null],
            }]);

        yield IntegerField::new('coachingAbility', 'Coaching')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Staff $s) => ['bar' => [
                'pct'   => $s->getCoachingAbility(),
                'label' => (string) $s->getCoachingAbility(),
            ]]);

        yield IntegerField::new('weeklySalary', 'Salary/wk')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('right')
            ->formatValue(fn ($v, Staff $s) => ['text' => $this->bmc->money($s->getWeeklySalary())]);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

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

        // Resolved archetype preview — read-only mirror of the client's dual-report
        // classification (positive + negative) over the eight traits above. Recomputed
        // live in the browser as the traits are edited; see ArchetypeResolverService.
        yield Field::new('archetypeSummary', 'Resolved Archetypes')
            ->setFormType(ArchetypeSummaryType::class)
            ->setFormTypeOptions([
                'mapped'    => false,
                'catalogue' => $this->archetypeResolver->catalogue(),
                'resolved'  => $this->archetypeResolver->resolve($this->currentPersonality()),
            ])
            ->setColumns(12)
            ->onlyOnForms();

        // ── Panel: Appearance ─────────────────────────────────────────────────
        yield FormField::addFieldset('Appearance', 'fa fa-user-circle')->hideOnIndex();

        yield Field::new('appearance')
            ->setFormType(AppearanceType::class)
            ->setFormTypeOptions(['person_type' => 'staff'])
            ->onlyOnForms();
    }

    /**
     * Personality matrix of the entity currently being edited, for the initial
     * server-side archetype render. Falls back to a fresh (all-10) profile on the
     * NEW page, where there is no persisted instance yet.
     */
    private function currentPersonality(): PersonalityProfile
    {
        $instance = $this->getContext()?->getEntity()?->getInstance();

        return $instance instanceof Staff ? $instance->getPersonality() : new PersonalityProfile();
    }
}

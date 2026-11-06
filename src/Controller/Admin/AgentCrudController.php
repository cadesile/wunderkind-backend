<?php

namespace App\Controller\Admin;

use App\Admin\BatchSelection;
use App\Admin\CsvExporter;
use App\Admin\FacetCounter;
use App\Controller\Admin\Batch\BmcBatchActionsTrait;
use App\Controller\Admin\Batch\PersonListTrait;
use App\Entity\Agent;
use App\Filter\Admin\AgeRangeFilter;
use App\Twig\BmcAdminExtension;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
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
    use BmcBatchActionsTrait;
    use PersonListTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FacetCounter $facets,
        private readonly BmcAdminExtension $bmc,
        private readonly BatchSelection $batchSelection,
        private readonly CsvExporter $csvExporter,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Agent::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // NEW stays disabled — Agent::__construct(string $name) requires an
        // argument EasyAdmin's default createEntity() can't supply.
        return $actions
            ->disable(Action::NEW, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('View agent')->setIcon('eye')
                ->setHtmlAttributes(['title' => 'View agent', 'aria-label' => 'View agent'])
                ->setCssClass('bmc-iconbtn'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a
                ->setLabel('Edit agent')->setIcon('pencil')
                ->setHtmlAttributes(['title' => 'Edit agent', 'aria-label' => 'Edit agent'])
                ->setCssClass('bmc-iconbtn'))
            ->addBatchAction(self::exportCsvAction());
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Agent')
            ->setEntityLabelInPlural('Agents')
            ->setDefaultSort(['name' => 'ASC'])
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['name', 'nationality'])
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig')
            ->addFormTheme('admin/form/appearance_theme.html.twig');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('nationality', 'Nationality')
                ->setChoices(self::nationalityChoices($this->em, Agent::class))
                ->canSelectMultiple())
            ->add(AgeRangeFilter::new('dob'))
            ->add(NumericFilter::new('reputation', 'Reputation'))
            ->add(NumericFilter::new('rating', 'Rating'))
            ->add(NumericFilter::new('experience', 'Experience'))
            ->add(NumericFilter::new('commissionRate', 'Commission %'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'People & market');
        $responseParameters->set('bmcDomain', 'people');
        $responseParameters->set('bmcTotal', $this->em->getRepository(Agent::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Agent name');
        $responseParameters->set('bmcUnits', ['commissionRate' => '%']);
        $responseParameters->set('bmcWidths', [
            'reputation' => 160, 'rating' => 160, 'players' => 90, 'commissionRate' => 110,
        ]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            ['nationality' => 'entity.nationality'],
        ));

        return $responseParameters;
    }

    /**
     * Agent, reputation, rating, clients, commission.
     *
     * Reputation and rating are both 0–100, so both get meters; the pair is
     * the point of the row — a well-regarded agent who is bad at the job is
     * exactly what you are scanning for.
     */
    private function indexFields(): iterable
    {
        yield TextField::new('name', 'Agent')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Agent $a) => [
                'flag' => self::flagFor($a->getNationality()),
                'text' => $a->getName(),
                'sub'  => self::personSub($a->getNationality(), $a->getDob()),
            ]);

        yield IntegerField::new('reputation', 'Reputation')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Agent $a) => ['bar' => [
                'pct' => $a->getReputation(), 'label' => (string) $a->getReputation(),
            ]]);

        yield IntegerField::new('rating', 'Rating')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Agent $a) => ['bar' => [
                'pct' => $a->getRating(), 'label' => (string) $a->getRating(),
            ]]);

        yield IntegerField::new('players', 'Clients')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('center')
            ->setSortable(false)
            ->formatValue(fn ($v, Agent $a) => 0 === $a->getPlayers()->count()
                ? ['text' => '—', 'tone' => 'dim']
                : ['text' => (string) $a->getPlayers()->count()]);

        yield NumberField::new('commissionRate', 'Commission')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('right')
            ->formatValue(fn ($v, Agent $a) => ['text' => rtrim(rtrim($a->getCommissionRate(), '0'), '.') . '%']);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

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

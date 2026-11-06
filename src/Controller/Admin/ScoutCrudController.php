<?php

namespace App\Controller\Admin;

use App\Admin\BatchSelection;
use App\Admin\CsvExporter;
use App\Admin\FacetCounter;
use App\Controller\Admin\Batch\BmcBatchActionsTrait;
use App\Controller\Admin\Batch\PersonListTrait;
use App\Entity\Scout;
use App\Filter\Admin\AgeRangeFilter;
use App\Filter\Admin\PenceRangeFilter;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class ScoutCrudController extends AbstractCrudController
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
        return Scout::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('View scout')->setIcon('eye')
                ->setHtmlAttributes(['title' => 'View scout', 'aria-label' => 'View scout'])
                ->setCssClass('bmc-iconbtn'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a
                ->setLabel('Edit scout')->setIcon('pencil')
                ->setHtmlAttributes(['title' => 'Edit scout', 'aria-label' => 'Edit scout'])
                ->setCssClass('bmc-iconbtn'))
            ->addBatchAction(self::exportCsvAction());
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Scout')
            ->setEntityLabelInPlural('Scouts')
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
                ->setChoices(self::nationalityChoices($this->em, Scout::class))
                ->canSelectMultiple())
            ->add(AgeRangeFilter::new('dob'))
            ->add(NumericFilter::new('experience', 'Experience'))
            ->add(PenceRangeFilter::new('weeklySalary', 'Wage/wk'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'People & market');
        $responseParameters->set('bmcDomain', 'people');
        $responseParameters->set('bmcTotal', $this->em->getRepository(Scout::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Scout name');
        $responseParameters->set('bmcUnits', ['weeklySalary' => '£']);
        $responseParameters->set('bmcWidths', ['experience' => 120, 'weeklySalary' => 110]);

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
     * Scout, experience, weekly wage.
     *
     * Experience is a plain figure rather than a meter: it runs roughly 4–34,
     * not 0–100, so a bar would read as "everyone is terrible" when it is
     * really just a different scale.
     */
    private function indexFields(): iterable
    {
        yield TextField::new('name', 'Scout')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Scout $s) => [
                // Nationality is a demonym here as elsewhere, so it needs the
                // reverse lookup before it can name a flag file.
                'flag' => self::flagFor($s->getNationality()),
                'text' => $s->getName(),
                'sub'  => self::personSub($s->getNationality(), $s->getDob()),
            ]);

        yield IntegerField::new('experience', 'Experience')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Scout $s) => ['text' => (string) $s->getExperience()]);

        yield IntegerField::new('weeklySalary', 'Wage/wk')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('right')
            ->formatValue(fn ($v, Scout $s) => ['text' => $this->bmc->money($s->getWeeklySalary())]);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield DateField::new('dob')->setLabel('Date of Birth');
        yield TextField::new('nationality');
        yield IntegerField::new('experience');
        yield IntegerField::new('weeklySalary', 'Weekly Wage')
            ->formatValue(fn ($v) => $v !== null ? '£' . number_format((int) $v / 100, 2) : '—')
            ->setHelp(
                'Pence per week. Normally derived from experience by ScoutWageCalculator at '
                . 'generation; set by hand here only to override one scout. '
                . '`app:backfill-scout-wages` fills in scouts that have none, and leaves '
                . 'anything non-zero alone unless given --force.',
            );
        yield TextareaField::new('judgementsJson', 'Judgements')
            ->setFormType(TextareaType::class)
            ->setHelp('JSON object of scout judgement attributes. Invalid JSON is silently ignored on save.')
            ->setFormTypeOption('attr', ['rows' => 8, 'class' => 'font-monospace'])
            ->hideOnIndex();

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
            ->setFormTypeOptions(['person_type' => 'staff'])
            ->onlyOnForms();
    }
}

<?php

namespace App\Controller\Admin;

use App\Admin\BatchSelection;
use App\Admin\CsvExporter;
use App\Admin\FacetCounter;
use App\Controller\Admin\Batch\BmcBatchActionsTrait;
use App\Controller\Admin\Batch\PersonListTrait;
use App\Entity\Sponsor;
use App\Enum\SponsorStatus;
use App\Filter\Admin\MarketPoolFilter;
use App\Filter\Admin\PenceRangeFilter;
use App\Twig\BmcAdminExtension;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use App\Enum\CompanySize;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SponsorCrudController extends AbstractCrudController
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
        return Sponsor::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('View sponsor')->setIcon('eye')
                ->setHtmlAttributes(['title' => 'View sponsor', 'aria-label' => 'View sponsor'])
                ->setCssClass('bmc-iconbtn'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a
                ->setLabel('Edit sponsor')->setIcon('pencil')
                ->setHtmlAttributes(['title' => 'Edit sponsor', 'aria-label' => 'Edit sponsor'])
                ->setCssClass('bmc-iconbtn'))
            ->addBatchAction(self::exportCsvAction());
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Sponsor')
            ->setEntityLabelInPlural('Sponsors')
            ->setDefaultSort(['company' => 'ASC'])
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['company', 'nationality', 'club.name'])
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(MarketPoolFilter::new())
            ->add(ChoiceFilter::new('status', 'Status')->setChoices(self::statusChoices())->canSelectMultiple())
            ->add(ChoiceFilter::new('size', 'Company size')->setChoices(self::sizeChoices())->canSelectMultiple())
            ->add(BooleanFilter::new('isActive', 'Active'))
            ->add(ChoiceFilter::new('nationality', 'Nationality')
                ->setChoices(self::nationalityChoices($this->em, Sponsor::class))
                ->canSelectMultiple())
            ->add(PenceRangeFilter::new('monthlyPayment', 'Monthly payment'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'People & market');
        $responseParameters->set('bmcDomain', 'club');
        $responseParameters->set('bmcTotal', $this->em->getRepository(Sponsor::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Company, nationality, club');
        $responseParameters->set('bmcSegmentLabels', ['isActive' => ['0' => 'NO', '1' => 'YES']]);
        $responseParameters->set('bmcFacetColumns', ['size' => 3]);
        $responseParameters->set('bmcUnits', ['monthlyPayment' => '£']);
        $responseParameters->set('bmcWidths', ['status' => 150, 'size' => 100, 'monthlyPayment' => 120]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            [
                'club'        => MarketPoolFilter::countExpression(),
                'status'      => 'entity.status',
                'size'        => 'entity.size',
                'isActive'    => 'entity.isActive',
                'nationality' => 'entity.nationality',
            ],
        ));

        return $responseParameters;
    }

    /** @return array<string, string> */
    private static function statusChoices(): array
    {
        $out = [];
        foreach (SponsorStatus::cases() as $case) {
            $out[ucwords(str_replace('_', ' ', $case->value))] = $case->value;
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function sizeChoices(): array
    {
        $out = [];
        foreach (CompanySize::cases() as $case) {
            $out[ucfirst($case->value)] = $case->value;
        }

        return $out;
    }

    /**
     * Company, contract status, size, what it pays, and who it pays.
     *
     * isActive and status overlap but are not the same thing — a contract can
     * be completed and the sponsor still be an active company available for
     * another deal — so status gets the column and isActive rides as a tag
     * only when it is false, which is the surprising case.
     */
    private function indexFields(): iterable
    {
        yield TextField::new('company', 'Company')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Sponsor $s) => [
                'flag' => self::flagFor($s->getNationality()),
                'text' => $s->getCompany(),
                'sub'  => $s->getNationality(),
                'tag'  => $s->isActive() ? null : ['label' => 'INACTIVE', 'color' => 'subtle'],
            ]);

        yield ChoiceField::new('status', 'Status')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Sponsor $s) => ['chip' => [
                'label' => strtoupper(str_replace('_', ' ', $s->getStatus()->value)),
                'color' => match ($s->getStatus()) {
                    SponsorStatus::ACTIVE           => 'positive',
                    SponsorStatus::COMPLETED        => null,
                    SponsorStatus::VOIDED           => 'danger',
                    SponsorStatus::EARLY_TERMINATED => 'warning',
                },
            ]]);

        yield ChoiceField::new('size', 'Size')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Sponsor $s) => ['chip' => [
                'label' => strtoupper($s->getSize()->value),
                'color' => match (strtolower($s->getSize()->value)) {
                    'large'  => 'positive',
                    'medium' => 'warning',
                    default  => null,
                },
            ]]);

        yield IntegerField::new('monthlyPayment', 'Monthly')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('right')
            ->formatValue(fn ($v, Sponsor $s) => 0 === $s->getMonthlyPayment()
                ? ['text' => '—', 'tone' => 'dim']
                : ['text' => $this->bmc->money($s->getMonthlyPayment())]);

        yield AssociationField::new('club', 'Club')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Sponsor $s) => null === $s->getClub()
                ? ['chip' => ['label' => 'IN POOL', 'color' => 'subtle']]
                : [
                    'img'  => $s->getClub()->getBadgeConfig(),
                    'text' => $s->getClub()->getName(),
                    'sub'  => $s->getClub()->getAbbreviation(),
                ]);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

        yield IdField::new('id')->hideOnForm();
        yield TextField::new('company');
        yield TextField::new('nationality');
        yield ChoiceField::new('size')
            ->setChoices(array_combine(
                array_map(fn($c) => ucfirst($c->value), CompanySize::cases()),
                CompanySize::cases()
            ));
        yield BooleanField::new('isActive');
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}

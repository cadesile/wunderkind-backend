<?php

namespace App\Controller\Admin;

use App\Admin\BatchSelection;
use App\Admin\CsvExporter;
use App\Admin\FacetCounter;
use App\Controller\Admin\Batch\BmcBatchActionsTrait;
use App\Controller\Admin\Batch\PersonListTrait;
use App\Entity\Investor;
use App\Enum\InvestorTier;
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

class InvestorCrudController extends AbstractCrudController
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
        return Investor::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('View investor')->setIcon('eye')
                ->setHtmlAttributes(['title' => 'View investor', 'aria-label' => 'View investor'])
                ->setCssClass('bmc-iconbtn'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a
                ->setLabel('Edit investor')->setIcon('pencil')
                ->setHtmlAttributes(['title' => 'Edit investor', 'aria-label' => 'Edit investor'])
                ->setCssClass('bmc-iconbtn'))
            ->addBatchAction(self::exportCsvAction());
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Investor')
            ->setEntityLabelInPlural('Investors')
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
            ->add(ChoiceFilter::new('tier', 'Tier')->setChoices(self::tierChoices())->canSelectMultiple())
            ->add(ChoiceFilter::new('size', 'Company size')->setChoices(self::sizeChoices())->canSelectMultiple())
            ->add(BooleanFilter::new('isActive', 'Active'))
            ->add(ChoiceFilter::new('nationality', 'Nationality')
                ->setChoices(self::nationalityChoices($this->em, Investor::class))
                ->canSelectMultiple())
            ->add(PenceRangeFilter::new('investmentAmount', 'Investment'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'People & market');
        $responseParameters->set('bmcDomain', 'club');
        $responseParameters->set('bmcTotal', $this->em->getRepository(Investor::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Company, nationality, club');
        $responseParameters->set('bmcSegmentLabels', ['isActive' => ['0' => 'NO', '1' => 'YES']]);
        $responseParameters->set('bmcFacetColumns', ['size' => 3]);
        $responseParameters->set('bmcUnits', ['investmentAmount' => '£']);
        $responseParameters->set('bmcWidths', [
            'tier' => 140, 'size' => 100, 'investmentAmount' => 110, 'percentageOwned' => 90,
        ]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            [
                'club'        => MarketPoolFilter::countExpression(),
                'tier'        => 'entity.tier',
                'size'        => 'entity.size',
                'isActive'    => 'entity.isActive',
                'nationality' => 'entity.nationality',
            ],
        ));

        return $responseParameters;
    }

    /** @return array<string, string> */
    private static function tierChoices(): array
    {
        $out = [];
        foreach (InvestorTier::cases() as $case) {
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
     * Company, tier, size, stake, and who holds it.
     *
     * The club column doubles as the pool indicator: an investor with no club
     * is one still available to be assigned, which is the state most worth
     * spotting on this page.
     */
    private function indexFields(): iterable
    {
        yield TextField::new('company', 'Company')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Investor $i) => [
                'flag' => self::flagFor($i->getNationality()),
                'text' => $i->getCompany(),
                'sub'  => $i->getNationality(),
                'tag'  => $i->isActive() ? null : ['label' => 'INACTIVE', 'color' => 'subtle'],
            ]);

        yield ChoiceField::new('tier', 'Tier')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Investor $i) => ['chip' => [
                'label' => strtoupper(str_replace('_', ' ', $i->getTier()->value)),
                'color' => match ($i->getTier()) {
                    InvestorTier::PRIVATE_EQUITY => 'positive',
                    InvestorTier::VC             => 'warning',
                    default                      => null,
                },
            ]]);

        yield ChoiceField::new('size', 'Size')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Investor $i) => ['chip' => [
                'label' => strtoupper($i->getSize()->value),
                'color' => match (strtolower($i->getSize()->value)) {
                    'large'  => 'positive',
                    'medium' => 'warning',
                    default  => null,
                },
            ]]);

        yield IntegerField::new('investmentAmount', 'Investment')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('right')
            ->formatValue(fn ($v, Investor $i) => 0 === $i->getInvestmentAmount()
                ? ['text' => '—', 'tone' => 'dim']
                : [
                    'text' => $this->bmc->money($i->getInvestmentAmount()),
                    'sub'  => rtrim(rtrim(number_format($i->getPercentageOwned(), 2), '0'), '.') . '%',
                ]);

        yield AssociationField::new('club', 'Club')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Investor $i) => null === $i->getClub()
                ? ['chip' => ['label' => 'IN POOL', 'color' => 'subtle']]
                : [
                    'img'  => $i->getClub()->getBadgeConfig(),
                    'text' => $i->getClub()->getName(),
                    'sub'  => $i->getClub()->getAbbreviation(),
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

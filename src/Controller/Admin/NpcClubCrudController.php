<?php

namespace App\Controller\Admin;

use App\Admin\BatchSelection;
use App\Admin\CsvExporter;
use App\Controller\Admin\Batch\BmcBatchActionsTrait;
use App\Admin\FacetCounter;
use App\Entity\NpcClub;
use App\Enum\Country;
use App\Filter\Admin\PenceRangeFilter;
use App\Twig\BmcAdminExtension;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use App\Enum\CitySize;
use App\Form\Type\KitIdentityType;
use App\Repository\NpcClubRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;

class NpcClubCrudController extends AbstractCrudController
{
    use BmcBatchActionsTrait;

    public function __construct(
        private readonly NpcClubRepository $npcClubRepository,
        private readonly EntityManagerInterface $em,
        private readonly FacetCounter $facets,
        private readonly BmcAdminExtension $bmc,
        private readonly BatchSelection $batchSelection,
        private readonly CsvExporter $csvExporter,
    ) {}

    public static function getEntityFqcn(): string
    {
        return NpcClub::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('NPC club')
            ->setEntityLabelInPlural('NPC clubs')
            ->setDefaultSort(['name' => 'ASC'])
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['name', 'abbreviation', 'stadiumName'])
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig')
            ->addFormTheme('admin/form/kit_identity_theme.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a
                ->setLabel('Edit club')->setIcon('pencil')
                ->setHtmlAttributes(['title' => 'Edit club', 'aria-label' => 'Edit club'])
                ->setCssClass('bmc-iconbtn'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $a) => $a
                ->setLabel('Delete club')->setIcon('trash-2')
                ->setHtmlAttributes(['title' => 'Delete club', 'aria-label' => 'Delete club'])
                ->setCssClass('bmc-iconbtn bmc-iconbtn--danger'))
            ->addBatchAction(self::exportCsvAction());
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('country', 'Country')->setChoices($this->countryChoices())->canSelectMultiple())
            ->add(ChoiceFilter::new('tier', 'Tier')
                ->setChoices(array_combine(array_map(static fn (int $t) => 'Tier ' . $t, range(1, 8)), range(1, 8)))
                ->canSelectMultiple())
            ->add(ChoiceFilter::new('citySize', 'City size')->setChoices($this->citySizeChoices())->canSelectMultiple())
            ->add(NumericFilter::new('reputation', 'Reputation'))
            ->add(PenceRangeFilter::new('balance', 'Budget'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'World');
        $responseParameters->set('bmcDomain', 'club');
        $responseParameters->set('bmcTotal', $this->em->getRepository(NpcClub::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Club, abbreviation, stadium');
        $responseParameters->set('bmcFlagFacets', ['country']);
        $responseParameters->set('bmcFacetColumns', ['tier' => 2, 'citySize' => 3]);
        $responseParameters->set('bmcUnits', ['balance' => '£']);
        $responseParameters->set('bmcWidths', [
            'country' => 92, 'tier' => 64, 'reputation' => 160, 'citySize' => 100, 'balance' => 100,
        ]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            ['country' => 'entity.country', 'tier' => 'entity.tier', 'citySize' => 'entity.citySize'],
        ));

        return $responseParameters;
    }

    /** @return array<string, string> */
    private function countryChoices(): array
    {
        $rows = $this->em->createQuery(
            'SELECT DISTINCT n.country FROM ' . NpcClub::class . ' n ORDER BY n.country ASC'
        )->getScalarResult();

        $choices = [];
        foreach ($rows as $row) {
            $code = $row['country'];
            $choices[Country::tryFrom($code)?->label() ?? strtoupper($code)] = $code;
        }

        return $choices;
    }

    /** @return array<string, string> */
    private function citySizeChoices(): array
    {
        $choices = [];
        foreach (CitySize::cases() as $case) {
            $choices[ucfirst(strtolower($case->value))] = $case->value;
        }

        return $choices;
    }

    /**
     * Club, nation, tier, reputation, city size, budget.
     *
     * The badge comes from $identity, not $badgeConfig — NPC clubs carry their
     * kit and badge together in one json column written by KitIdentityType,
     * where player clubs keep three separate ones.
     */
    private function indexFields(): iterable
    {
        yield TextField::new('name', 'Club')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, NpcClub $c) => [
                'img'  => $c->getIdentity(),
                'text' => $c->getName(),
                'sub'  => trim(($c->getAbbreviation() ?? '') . ($c->getStadiumName() ? ' · ' . $c->getStadiumName() : ''), ' ·'),
            ]);

        yield TextField::new('country', 'Nation')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, NpcClub $c) => ['flag' => $c->getCountry(), 'text' => strtoupper($c->getCountry())]);

        yield IntegerField::new('tier', 'Tier')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('center')
            ->formatValue(fn ($v, NpcClub $c) => ['text' => 'T' . $c->getTier()]);

        yield IntegerField::new('reputation', 'Reputation')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, NpcClub $c) => ['bar' => [
                'pct'   => $c->getReputation(),
                'label' => (string) $c->getReputation(),
            ]]);

        yield TextField::new('citySize', 'City')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, NpcClub $c) => ['chip' => [
                'label' => strtoupper($c->getCitySize()->value),
                'color' => match (strtolower($c->getCitySize()->value)) {
                    'big'    => 'positive',
                    'medium' => 'warning',
                    default  => null,
                },
            ]]);

        yield IntegerField::new('balance', 'Budget')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('right')
            ->formatValue(fn ($v, NpcClub $c) => ['text' => $this->bmc->money($c->getBalance())]);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

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
        // Not independently editable — the home kit in "Kit & Badge" below is
        // the single source of truth and keeps these in sync on save (see
        // NpcClub::setIdentity()). Still shown read-only on the detail page.
        yield ColorField::new('primaryColor')->hideOnIndex()->hideOnForm();
        yield ColorField::new('secondaryColor')->hideOnIndex()->hideOnForm();
        yield TextField::new('stadiumName')
            ->setHelp('Optional stadium name, e.g. Estadio El Cid')
            ->hideOnIndex();
        $regions = $this->npcClubRepository->findDistinctRegions();
        yield ChoiceField::new('region')
            ->setChoices(array_combine($regions, $regions))
            ->setFormTypeOptions([
                'required'    => false,
                'placeholder' => '-- Not set --',
            ])
            ->setHelp('City\'s subnational region — drawn from regions already in use, e.g. Greater Manchester, Catalonia')
            ->hideOnIndex();
        yield ChoiceField::new('citySize')
            ->setChoices([
                'Big'    => CitySize::BIG,
                'Medium' => CitySize::MEDIUM,
                'Small'  => CitySize::SMALL,
            ])
            ->renderAsBadges([
                CitySize::BIG->value    => 'success',
                CitySize::MEDIUM->value => 'warning',
                CitySize::SMALL->value  => 'secondary',
            ]);
        yield IntegerField::new('populationSize')
            ->setHelp('Approximate real-world city population')
            ->hideOnIndex();
        yield BooleanField::new('isCapital')
            ->setHelp('Is this city the national capital?')
            ->renderAsSwitch(true)
            ->hideOnIndex();
        yield TextareaField::new('facilitiesJson', 'Facilities')
            ->setHelp('JSON: {"training_pitch": 6, "north_stand": 4, ...}')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();

        // ── Panel: Kit & Badge ────────────────────────────────────────────────
        yield FormField::addFieldset('Kit & Badge', 'fa fa-shirt')->hideOnIndex();

        yield Field::new('identity')
            ->setFormType(KitIdentityType::class)
            ->onlyOnForms();
    }
}

<?php

namespace App\Controller\Admin;

use App\Admin\BatchSelection;
use App\Admin\CsvExporter;
use App\Admin\FacetCounter;
use App\Controller\Admin\Batch\BmcBatchActionsTrait;
use App\Controller\Admin\Batch\PersonListTrait;
use App\Entity\Guardian;
use App\Filter\Admin\AgeRangeFilter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class GuardianCrudController extends AbstractCrudController
{
    use BmcBatchActionsTrait;
    use PersonListTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FacetCounter $facets,
        private readonly BatchSelection $batchSelection,
        private readonly CsvExporter $csvExporter,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Guardian::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('View guardian')->setIcon('eye')
                ->setHtmlAttributes(['title' => 'View guardian', 'aria-label' => 'View guardian'])
                ->setCssClass('bmc-iconbtn'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a
                ->setLabel('Edit guardian')->setIcon('pencil')
                ->setHtmlAttributes(['title' => 'Edit guardian', 'aria-label' => 'Edit guardian'])
                ->setCssClass('bmc-iconbtn'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $a) => $a
                ->setLabel('Delete guardian')->setIcon('trash-2')
                ->setHtmlAttributes(['title' => 'Delete guardian', 'aria-label' => 'Delete guardian'])
                ->setCssClass('bmc-iconbtn bmc-iconbtn--danger'))
            ->addBatchAction(self::exportCsvAction());
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Guardian')
            ->setEntityLabelInPlural('Guardians')
            ->setDefaultSort(['lastName' => 'ASC'])
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['firstName', 'lastName', 'contactEmail', 'player.firstName', 'player.lastName'])
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('gender', 'Gender')
                ->setChoices(['Male' => 'male', 'Female' => 'female'])
                ->canSelectMultiple())
            ->add(AgeRangeFilter::new('dateOfBirth'))
            ->add(NumericFilter::new('demandLevel', 'Demand level'))
            ->add(NumericFilter::new('loyaltyToClub', 'Loyalty to club'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'People & market');
        $responseParameters->set('bmcDomain', 'people');
        $responseParameters->set('bmcTotal', $this->em->getRepository(Guardian::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Guardian or player name');
        $responseParameters->set('bmcFacetColumns', ['gender' => 2]);
        $responseParameters->set('bmcWidths', ['demandLevel' => 160, 'loyaltyToClub' => 160]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            ['gender' => 'entity.gender'],
        ));

        return $responseParameters;
    }

    /**
     * Guardian, the player they speak for, demand, loyalty.
     *
     * Demand runs 1–10 where everything else here is 0–100, so its bar is
     * scaled to fill — otherwise a maximally demanding guardian would show a
     * tenth of a bar and read as the opposite of what they are. The label
     * keeps the real number.
     */
    private function indexFields(): iterable
    {
        yield TextField::new('lastName', 'Guardian')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Guardian $g) => [
                'text' => $g->getFullName(),
                'sub'  => self::personSub(ucfirst($g->getGender()), $g->getDateOfBirth()),
            ]);

        yield AssociationField::new('player', 'Player')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Guardian $g) => [
                'flag' => self::flagFor($g->getPlayer()->getNationality()),
                'text' => $g->getPlayer()->getFullName(),
                'sub'  => $g->getPlayer()->getPositionValue(),
            ]);

        yield IntegerField::new('demandLevel', 'Demand')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Guardian $g) => ['bar' => [
                'pct'   => $g->getDemandLevel() * 10,
                'label' => $g->getDemandLevel() . ' / 10',
            ]]);

        yield IntegerField::new('loyaltyToClub', 'Loyalty')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Guardian $g) => ['bar' => [
                'pct'   => $g->getLoyaltyToClub(),
                'label' => (string) $g->getLoyaltyToClub(),
            ]]);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

        yield IdField::new('id')->hideOnForm();
        yield TextField::new('firstName', 'First Name');
        yield TextField::new('lastName', 'Last Name');
        yield ChoiceField::new('gender', 'Gender')
            ->setChoices(['Male' => 'male', 'Female' => 'female']);
        yield IntegerField::new('demandLevel', 'Demand Level')
            ->setHelp('1 (relaxed) to 10 (very demanding)');
        yield IntegerField::new('loyaltyToClub', 'Loyalty to Club')
            ->setHelp('0–100');
        yield AssociationField::new('player', 'Player')->hideOnForm();
        yield TextField::new('contactEmail', 'Contact Email')->onlyOnForms();
    }
}

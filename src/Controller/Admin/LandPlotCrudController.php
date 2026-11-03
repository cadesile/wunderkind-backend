<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Location\LandPlot;
use App\Enum\Location\LandPlotStatus;
use App\Repository\Location\LandPlotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * The buyable plots. One per town after seeding; add more to let several clubs share a city.
 *
 * `plotNumber` is assigned automatically on create — UNIQUE(location, plot_number) exists, and
 * making an operator pick a free number by hand is a constraint violation waiting to happen.
 *
 * `landPenceOverride` is blank for the common case and inherits the location's own value, so
 * revaluing a town reprices all of its plots in one edit. Set it only where one plot is
 * genuinely worth more than its neighbours.
 */
class LandPlotCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly LandPlotRepository $plotRepository,
    ) {}

    public static function getEntityFqcn(): string
    {
        return LandPlot::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Land Plot')
            ->setEntityLabelInPlural('Land Plots')
            ->setDefaultSort(['location' => 'ASC', 'plotNumber' => 'ASC'])
            ->setPaginatorPageSize(50)
            ->setHelp(
                'index',
                'A plot is what a club actually buys. Seeding creates one per town, so one club per town — '
                . 'add more plots to a city to let several clubs share it. Leave the price override blank '
                . 'to inherit the town\'s own land value.',
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('location'))
            ->add(ChoiceFilter::new('status')->setChoices(self::statusChoices()));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('location')->setRequired(true);

        yield IntegerField::new('plotNumber', 'Plot #')
            ->setDisabled()
            ->setHelp('Assigned automatically — UNIQUE per location.');

        yield TextField::new('label')
            ->setRequired(false)
            ->setHelp('Optional, e.g. "Salford Quays".');

        yield IntegerField::new('landPenceOverride', 'Price override (pence)')
            ->setRequired(false)
            ->setHelp('Blank inherits the town\'s land value, so revaluing the town reprices every plot on it.');

        yield IntegerField::new('effectiveLandPence', 'Effective price')
            ->onlyOnIndex()
            ->setDisabled()
            ->formatValue(static fn (mixed $value, LandPlot $plot): string => $plot->getEffectiveLandPence() === null
                ? 'not priced'
                : number_format($plot->getEffectiveLandPence() / 100, 2));

        // EnumType, not a string choice list — see LocationCrudController's note on the 500.
        yield ChoiceField::new('status')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => LandPlotStatus::class])
            ->formatValue(static fn ($value, ?LandPlot $entity): string => $entity?->getStatus()->value ?? '')
            ->setHelp('Retire a plot rather than deleting it — a claim may reference it.');

        yield DateTimeField::new('updatedAt', 'Updated')->hideOnForm()->hideOnIndex();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof LandPlot) {
            // Assigned here rather than left to the operator: UNIQUE(location, plot_number)
            // would otherwise throw out of flush() and close the shared EntityManager.
            $entityInstance->setPlotNumber($this->plotRepository->nextPlotNumber($entityInstance->getLocation()));
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof LandPlot) {
            parent::deleteEntity($entityManager, $entityInstance);

            return;
        }

        if ($this->plotRepository->countByLocation($entityInstance->getLocation()) <= 1) {
            $this->addFlash('danger', sprintf(
                '%s is the only plot at %s. Deleting it would leave the town unbuyable — retire the '
                . 'location instead if that is the intent.',
                (string) $entityInstance,
                $entityInstance->getLocation()->getTown(),
            ));

            return;
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    /** @return array<string, string> */
    private static function statusChoices(): array
    {
        return ['Active' => LandPlotStatus::ACTIVE->value, 'Retired' => LandPlotStatus::RETIRED->value];
    }
}

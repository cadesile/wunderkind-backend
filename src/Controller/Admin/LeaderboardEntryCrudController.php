<?php

namespace App\Controller\Admin;

use App\Admin\FacetCounter;
use App\Entity\LeaderboardEntry;
use App\Filter\Admin\LeaderboardPeriodFilter;
use App\Twig\BmcAdminExtension;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use App\Enum\LeaderboardCategory;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class LeaderboardEntryCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FacetCounter $facets,
        private readonly BmcAdminExtension $bmc,
    ) {}

    public static function getEntityFqcn(): string
    {
        return LeaderboardEntry::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // This page had no row actions at all: EasyAdmin only auto-adds
        // EDIT/DELETE, so disabling both left the column empty.
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('Open entry')->setIcon('external-link')
                ->setHtmlAttributes(['title' => 'Open entry', 'aria-label' => 'Open entry'])
                ->setCssClass('bmc-iconbtn'));
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Leaderboard entry')
            ->setEntityLabelInPlural('Leaderboard entries')
            // Position ascending, not score descending: the boards cover
            // different units, so rank is the only ordering that means the
            // same thing on every one of them.
            ->setDefaultSort(['rank' => 'ASC'])
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['club.name', 'club.abbreviation'])
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(LeaderboardPeriodFilter::new())
            ->add(ChoiceFilter::new('category', 'Board')->setChoices($this->categoryChoices())->canSelectMultiple())
            ->add(NumericFilter::new('rank', 'Position'))
            ->add(DateTimeFilter::new('updatedAt', 'Updated'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'Seasons & competitions');
        $responseParameters->set('bmcDomain', 'match');
        $responseParameters->set('bmcTotal', $this->em->getRepository(LeaderboardEntry::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Club name');
        $responseParameters->set('bmcWidths', [
            'rank' => 64, 'category' => 190, 'period' => 120, 'score' => 104, 'updatedAt' => 104,
        ]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            ['period' => LeaderboardPeriodFilter::countExpression(), 'category' => 'entity.category'],
        ));

        return $responseParameters;
    }

    /** @return array<string, string> */
    private function categoryChoices(): array
    {
        $choices = [];
        foreach (LeaderboardCategory::cases() as $case) {
            $choices[ucwords(str_replace('_', ' ', $case->value))] = $case->value;
        }

        return $choices;
    }

    /**
     * Position, club, board, period, score, updated.
     *
     * Score has no single unit — most boards count things, CAREER_EARNINGS is
     * money — so the cell asks the category which it is rather than formatting
     * everything one way.
     */
    private function indexFields(): iterable
    {
        yield IntegerField::new('rank', 'Pos')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('center')
            ->formatValue(fn ($v, LeaderboardEntry $e) => null === $e->getRank() ? null : [
                'text' => (string) $e->getRank(),
                'big'  => true,
                // The only gold on the page: first place.
                'tone' => 1 === $e->getRank() ? 'accent' : null,
            ]);

        yield AssociationField::new('club', 'Club')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, LeaderboardEntry $e) => [
                'img'  => $e->getClub()?->getBadgeConfig(),
                'text' => $e->getClub()?->getName() ?? 'Deleted club',
                'tone' => $e->getClub() ? null : 'dim',
                'sub'  => $e->getClub()?->getAbbreviation(),
            ]);

        yield TextField::new('category', 'Board')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, LeaderboardEntry $e) => ['chip' => [
                'label' => $e->getDisplayLabel() ?: ucwords(str_replace('_', ' ', $e->getCategoryValue())),
                'color' => null,
            ]]);

        yield TextField::new('period', 'Period')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, LeaderboardEntry $e) => [
                'text' => LeaderboardPeriodFilter::ALL_TIME === $e->getPeriod() ? 'All-time' : $e->getPeriod(),
            ]);

        yield IntegerField::new('score', 'Score')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('right')
            ->formatValue(fn ($v, LeaderboardEntry $e) => [
                'text' => LeaderboardCategory::CAREER_EARNINGS === $e->getCategory()
                    ? $this->bmc->money((int) $e->getScore())
                    : number_format((int) $e->getScore()),
            ]);

        yield DateTimeField::new('updatedAt', 'Updated')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, LeaderboardEntry $e) => ['text' => $this->bmc->ago($e->getUpdatedAt()), 'tone' => 'dim']);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('club');
        yield TextField::new('categoryValue', 'Category');
        yield TextField::new('period');
        yield IntegerField::new('score')
            ->formatValue(fn($v, $e) =>
                $v !== null && $e instanceof LeaderboardEntry && $e->getCategory() === LeaderboardCategory::CAREER_EARNINGS
                    ? '£' . number_format((int) $v / 100)
                    : number_format((int) ($v ?? 0))
            );
        yield IntegerField::new('rank')->setRequired(false);
        yield DateTimeField::new('updatedAt')->setFormat('yyyy-MM-dd HH:mm');
    }
}

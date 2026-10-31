<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Enum\Leaderboard\AwardCadence;
use App\Enum\Leaderboard\PeriodArchiveStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Read-only: an archive is immutable history, written by the close job — the
 * LeaderboardEntryCrudController / UserLedgerCrudController pattern.
 *
 * A row sitting at `pending` past its 300s claim lease is the operational signal that
 * something is failing mid-close. Its live leaderboard_entry rows are NOT pruned in that
 * state, so the live board is still the truthful answer for that period until the close
 * completes.
 */
class LeaderboardPeriodArchiveCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LeaderboardPeriodArchive::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Period Archive')
            ->setEntityLabelInPlural('Period Archives')
            ->setDefaultSort(['closedAt' => 'DESC'])
            ->setDefaultRowAction(Action::DETAIL)
            ->setHelp('index', 'One immutable row per closed period. Written by app:leaderboards:close-periods; never hand-edited. A row stuck at "pending" past its 300s claim lease means a close failed part-way — its live leaderboard rows are still unpruned.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('definition'))
            ->add(ChoiceFilter::new('status')->setChoices(self::statusChoices()))
            ->add(ChoiceFilter::new('cadence')->setChoices(self::cadenceChoices()));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();

        yield TextField::new('definitionName', 'Definition');
        yield TextField::new('periodKey', 'Period');
        yield TextField::new('category')
            ->setValue('')
            ->formatValue(static fn ($value, $entity): string => $entity?->getCategory()->value ?? '');
        yield TextField::new('cadence')
            ->setValue('')
            ->formatValue(static fn ($value, $entity): string => $entity?->getCadence()->label() ?? '');

        // Read-only screen, so the choice list only has to render: enum CASES as values plus
        // a formatValue, the GameEventTemplateCrudController pattern. String values would
        // 500 on any form page for this entity — there is none, but the filters share the
        // list.
        yield ChoiceField::new('status')
            ->setChoices(self::statusChoices())
            ->formatValue(static fn ($value, $entity): string => $entity?->getStatus()->label() ?? '');

        yield IntegerField::new('entryCount', 'Eligible clubs')
            ->setHelp('Every eligible club on the board at close — not capped.');
        yield IntegerField::new('archivedEntryCount', 'Rows frozen')
            ->setHelp('Bounded to the top 100, plus every awarded club regardless of rank.');
        yield IntegerField::new('placementsAwarded', 'Awards');

        yield DateTimeField::new('periodStartsAt', 'Period start')
            ->setFormat('yyyy-MM-dd HH:mm:ss')
            ->hideOnIndex()
            ->setHelp('Frozen at close — nothing downstream re-derives this from the period key.');
        yield DateTimeField::new('periodEndsAt', 'Period end (exclusive)')
            ->setFormat('yyyy-MM-dd HH:mm:ss')
            ->hideOnIndex();
        yield TextField::new('baselinePeriodKey', 'Baseline period')
            ->hideOnIndex()
            ->setHelp('Which close last supplied the baselines this one consumed. Empty on a definition\'s first close.');

        yield DateTimeField::new('claimedAt', 'Claimed at')->setFormat('yyyy-MM-dd HH:mm:ss')->hideOnIndex();
        yield DateTimeField::new('closedAt', 'Closed at')->setFormat('yyyy-MM-dd HH:mm:ss');

        yield AssociationField::new('definition', 'Definition row')
            ->onlyOnDetail()
            ->setHelp('Null if the definition has since been deleted — the archive deliberately outlives it.');
    }

    /** @return array<string, PeriodArchiveStatus> */
    private static function statusChoices(): array
    {
        $choices = [];
        foreach (PeriodArchiveStatus::cases() as $status) {
            $choices[$status->label()] = $status;
        }

        return $choices;
    }

    /** @return array<string, AwardCadence> */
    private static function cadenceChoices(): array
    {
        $choices = [];
        foreach (AwardCadence::cases() as $cadence) {
            $choices[$cadence->label()] = $cadence;
        }

        return $choices;
    }
}

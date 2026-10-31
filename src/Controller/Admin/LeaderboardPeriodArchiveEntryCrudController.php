<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * Read-only, and THE "awards won by this account" screen — which is why userId is a
 * denormalised indexed string and `awarded` is a plain boolean: both are filterable here
 * and served by idx_lpae_user without a join.
 *
 * club_id / user_id carry no foreign key on purpose, so a row survives the club and the
 * account being deleted. Account deletion nulls user_id rather than removing the row: the
 * rank happened, the account identity goes — so this screen stops returning rows for a
 * deleted account, which is correct.
 */
class LeaderboardPeriodArchiveEntryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LeaderboardPeriodArchiveEntry::class;
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
            ->setEntityLabelInSingular('Leaderboard Award')
            ->setEntityLabelInPlural('Leaderboard Awards')
            ->setDefaultSort(['archive' => 'DESC', 'rank' => 'ASC'])
            ->setDefaultRowAction(Action::DETAIL)
            ->setHelp('index', 'Frozen board rows. Filter on "Awarded" for prizes only, or on an account id for everything that account has ever placed. Scores are period GROWTH; the cumulative and the baseline that produced them are kept beside each row.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('archive'))
            ->add(BooleanFilter::new('awarded'))
            ->add(BooleanFilter::new('awardEligible'))
            ->add(TextFilter::new('userId', 'Account id'))
            ->add(TextFilter::new('clubId', 'Club id'))
            ->add(TextFilter::new('clubName'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();

        yield AssociationField::new('archive', 'Archive');
        yield IntegerField::new('rank', 'Rank');
        yield TextField::new('clubName', 'Club');
        yield IntegerField::new('score', 'Growth');

        yield BooleanField::new('awarded')->renderAsSwitch(false);
        yield IntegerField::new('place')->setHelp('The placement place, when awarded.');

        yield IntegerField::new('cumulativeScore', 'Cumulative at close')->hideOnIndex();
        yield IntegerField::new('baselineScore', 'Baseline subtracted')->hideOnIndex();
        yield BooleanField::new('baselineMissing', 'Baseline missing')
            ->renderAsSwitch(false)
            ->hideOnIndex()
            ->setHelp('The club had no stamped baseline for this period, so 0 was used.');
        yield BooleanField::new('awardEligible', 'Award eligible')
            ->renderAsSwitch(false)
            ->hideOnIndex()
            ->setHelp('False when a club arrived on the board mid-period with no baseline and was created before the period started — its cumulative is partly pre-period, so it is listed honestly but cannot win.');

        yield TextField::new('displayLabel', 'Named performer')->hideOnIndex();
        yield TextField::new('clubId', 'Club id')->onlyOnDetail();
        yield TextField::new('userId', 'Account id')->onlyOnDetail();
        yield TextField::new('countryCode', 'Country')->hideOnIndex();

        yield AssociationField::new('rewardTemplate', 'Reward template')->onlyOnDetail();
        yield TextField::new('inboxMessageId', 'Inbox message id')
            ->onlyOnDetail()
            ->setHelp('A plain id, not a foreign key — inbox messages cascade-delete with the club, and the award must not vanish with its delivery receipt.');

        yield TextField::new('appliedEffects', 'Applied effects')
            ->onlyOnDetail()
            ->setValue('')
            ->formatValue(static function ($value, $entity): string {
                $effects = $entity?->getAppliedEffectsJson();

                return $effects === null || $effects === []
                    ? '—'
                    : '<pre class="mb-0">' . htmlspecialchars((string) json_encode($effects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES) . '</pre>';
            });
    }
}

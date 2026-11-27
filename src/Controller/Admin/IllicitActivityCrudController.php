<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\IllicitActivity;
use App\Enum\IllicitActivityKind;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Operate › Accounts › Illicit activity (2026-11-27): the cheats clubs reported in their syncs,
 * starting with PA sham sponsors. Read only: rows come from the sync and are never deleted.
 */
class IllicitActivityCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return IllicitActivity::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Illicit activity')
            ->setEntityLabelInPlural('Illicit activity')
            ->setDefaultSort(['lastSeenAt' => 'DESC'])
            ->setPaginatorPageSize(25)
            ->setSearchFields(['club.name', 'clientId']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $kinds = [];
        foreach (IllicitActivityKind::cases() as $k) {
            $kinds[$k->value] = $k;
        }

        return $filters
            ->add(EntityFilter::new('club'))
            ->add(ChoiceFilter::new('kind')->setChoices($kinds))
            ->add(ChoiceFilter::new('status')->setChoices(array_combine(IllicitActivity::STATUSES, IllicitActivity::STATUSES)));
    }

    public function configureFields(string $pageName): iterable
    {
        $pence = static fn ($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—';

        yield AssociationField::new('club');
        yield ChoiceField::new('kind')->setChoices(array_combine(
            array_map(static fn (IllicitActivityKind $k) => $k->value, IllicitActivityKind::cases()),
            IllicitActivityKind::cases(),
        ));
        yield TextField::new('detailName', 'Name');
        yield IntegerField::new('detailWeeklyPayment', 'Weekly')->formatValue($pence);
        yield IntegerField::new('suspicion');
        yield TextField::new('status');
        yield IntegerField::new('startSeason', 'Season');
        yield IntegerField::new('startWeek', 'Start wk');
        yield IntegerField::new('endedWeek', 'Ended wk');
        yield DateTimeField::new('firstSeenAt', 'First seen')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('lastSeenAt', 'Last seen')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('missingSince', 'Missing since')->setFormat('yyyy-MM-dd HH:mm');
        yield TextField::new('clientId', 'Device id')->onlyOnDetail();
        yield CodeEditorField::new('detailsJson', 'Details')->setLanguage('js')->onlyOnDetail();
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\UserLedger;
use App\Enum\UserLedgerEntryType;
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
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Read-only audit trail of every UserLedger entry (currently just dividend draws) — written
 * by UserLedgerService, never hand-edited, same reasoning as NotificationLogCrudController /
 * DeletionRequestCrudController. balanceBefore/After makes each row self-auditing: a gap or
 * mismatch between one row's balanceAfterPence and the next row's balanceBeforePence (for the
 * same user) is a bug, not expected drift.
 */
class UserLedgerCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return UserLedger::class;
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
            ->setEntityLabelInSingular('User Ledger Entry')
            ->setEntityLabelInPlural('User Ledger')
            ->setDefaultSort(['recordedAt' => 'DESC'])
            ->setDefaultRowAction(Action::DETAIL)
            ->setHelp('index', 'Audit trail of dividend draws centralized onto a user across every club they own. See UserLedgerService.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('user'))
            ->add(EntityFilter::new('club'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnIndex();
        yield AssociationField::new('user');
        yield AssociationField::new('club');
        yield ChoiceField::new('type')->setChoices(self::typeChoices());
        yield IntegerField::new('amountPence', 'Amount (pence)');
        yield IntegerField::new('balanceBeforePence', 'Balance before (pence)');
        yield IntegerField::new('balanceAfterPence', 'Balance after (pence)');
        yield DateTimeField::new('occurredAt', 'In-game date')->setFormat('yyyy-MM-dd HH:mm:ss');
        yield DateTimeField::new('recordedAt', 'Real-world date')->setFormat('yyyy-MM-dd HH:mm:ss');
        yield TextField::new('description')->hideOnIndex();
        yield AssociationField::new('sourceSyncRecord', 'Source sync')->hideOnIndex();
        yield IntegerField::new('sourceLedgerIndex', 'Ledger index')->hideOnIndex();
    }

    /** @return array<string, string> label => value, as EasyAdmin expects. */
    private static function typeChoices(): array
    {
        $choices = [];
        foreach (UserLedgerEntryType::cases() as $type) {
            $choices[$type->name] = $type->value;
        }

        return $choices;
    }
}

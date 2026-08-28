<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DeletionRequest;
use App\Enum\DeletionRequestStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * Read-only view of the account-deletion audit trail.
 *
 * Every action is disabled deliberately: this table is the evidence you show a
 * store reviewer, so it must not be editable from the UI. Filter by status
 * `failed` to find the requests that need manual follow-up.
 */
class DeletionRequestCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return DeletionRequest::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Deletion Request')
            ->setEntityLabelInPlural('Deletion Requests')
            ->setDefaultSort(['requestedAt' => 'DESC'])
            ->setHelp('index', 'Audit trail for web account deletions (GET /delete-account). Read-only by design.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('email'))
            ->add(ChoiceFilter::new('status')->setChoices(self::statusChoices()));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnIndex();
        yield EmailField::new('email');
        yield ChoiceField::new('status')
            ->setChoices(self::statusChoices())
            ->renderAsBadges([
                DeletionRequestStatus::COMPLETED->value                    => 'success',
                DeletionRequestStatus::REJECTED_INVALID_CREDENTIALS->value => 'secondary',
                DeletionRequestStatus::REJECTED_GUEST->value               => 'warning',
                DeletionRequestStatus::FAILED->value                       => 'danger',
            ]);
        yield IntegerField::new('clubsDeleted', 'Clubs');
        yield TextField::new('ipAddress', 'IP')->hideOnIndex();
        yield TextField::new('failureReason', 'Failure')->hideOnIndex();
        yield DateTimeField::new('requestedAt')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('completedAt')->setFormat('yyyy-MM-dd HH:mm')->hideOnIndex();
    }

    /** @return array<string, string> label => value, as EasyAdmin expects. */
    private static function statusChoices(): array
    {
        $choices = [];
        foreach (DeletionRequestStatus::cases() as $status) {
            $choices[$status->label()] = $status->value;
        }

        return $choices;
    }
}

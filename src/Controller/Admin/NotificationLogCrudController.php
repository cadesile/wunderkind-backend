<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\NotificationLog;
use App\Enum\NotificationLogStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * Read-only audit trail of every push-related Messenger message actually processed — written by
 * NotificationLoggingSubscriber. Every action is disabled deliberately, same reasoning as
 * DeletionRequestCrudController: this is an audit record, not something to hand-edit. Filter by
 * status `failed` to find sends that need attention.
 */
class NotificationLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NotificationLog::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Notification Log')
            ->setEntityLabelInPlural('Notification Logs')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp('index', 'Audit trail for every push-notification message the async Messenger transport has processed, success or failure. Filter by status "Failed" to find sends that need attention.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices(self::statusChoices()))
            ->add(TextFilter::new('messageType'))
            ->add(TextFilter::new('summary'))
            ->add(TextFilter::new('errorMessage'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnIndex();
        yield ChoiceField::new('status')
            ->setChoices(self::statusChoices())
            ->renderAsBadges([
                NotificationLogStatus::SUCCESS->value => 'success',
                NotificationLogStatus::FAILED->value  => 'danger',
            ]);
        yield TextField::new('messageType', 'Type');
        yield TextField::new('summary');
        yield TextField::new('errorMessage', 'Error')->hideOnIndex();
        yield DateTimeField::new('createdAt')->setFormat('yyyy-MM-dd HH:mm:ss');
        yield CodeEditorField::new('detailJsonPretty', 'Detail')
            ->setLanguage('js')
            ->onlyOnDetail();
    }

    /** @return array<string, string> label => value, as EasyAdmin expects. */
    private static function statusChoices(): array
    {
        $choices = [];
        foreach (NotificationLogStatus::cases() as $status) {
            $choices[$status->label()] = $status->value;
        }

        return $choices;
    }
}

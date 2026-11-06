<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\BatchSelection;
use App\Admin\CsvExporter;
use App\Controller\Admin\Batch\BmcBatchActionsTrait;
use App\Admin\FacetCounter;
use App\Entity\NotificationLog;
use App\Twig\BmcAdminExtension;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
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
    use BmcBatchActionsTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FacetCounter $facets,
        private readonly BmcAdminExtension $bmc,
        private readonly BatchSelection $batchSelection,
        private readonly CsvExporter $csvExporter,
    ) {}

    public static function getEntityFqcn(): string
    {
        return NotificationLog::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // DETAIL isn't a default index-page row action here — EasyAdmin only auto-adds
        // EDIT/DELETE, and disabling those (this is a read-only audit log) leaves the actions
        // column empty rather than falling back to DETAIL, so it must be added explicitly for
        // setDefaultRowAction(Action::DETAIL) below (configureCrud()) to have anything to find.
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('View payload')->setIcon('eye')
                ->setHtmlAttributes(['title' => 'View payload', 'aria-label' => 'View payload'])
                ->setCssClass('bmc-iconbtn'))
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->addBatchAction(self::exportCsvAction());
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Notification Log')
            ->setEntityLabelInPlural('Notification Logs')
            ->setDefaultSort(['createdAt' => 'DESC'])
            // EasyAdmin's row-click-to-navigate is opt-in, not automatic just because DETAIL is
            // enabled — without this, clicking a row does nothing, which is exactly why the
            // index's compact "Payload" column (necessarily truncated) looked like a dead end.
            ->setDefaultRowAction(Action::DETAIL)
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['summary', 'errorMessage', 'messageType'])
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig')
            ->setHelp('index', 'Audit trail for every push-notification message the async Messenger transport has processed, success or failure. Filter by status "Failed" to find sends that need attention. Click a row to see its full payload.');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Status')->setChoices(self::statusChoices())->canSelectMultiple())
            ->add(ChoiceFilter::new('messageType', 'Message type')->setChoices($this->messageTypeChoices())->canSelectMultiple())
            ->add(DateTimeFilter::new('createdAt', 'Sent'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'Comms');
        $responseParameters->set('bmcDomain', 'match');
        $responseParameters->set('bmcTotal', $this->em->getRepository(NotificationLog::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Summary or error text');
        $responseParameters->set('bmcFacetColumns', ['status' => 2]);
        $responseParameters->set('bmcWidths', ['status' => 112, 'messageType' => 200, 'createdAt' => 180]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            ['status' => 'entity.status', 'messageType' => 'entity.messageType'],
        ));

        return $responseParameters;
    }

    /**
     * Status, type, summary, when.
     *
     * The failure reason rides as a red sub-line under the summary rather than
     * getting a column: on a healthy day it is empty on every row, and a column
     * that is usually blank is a column spending width on nothing.
     */
    private function indexFields(): iterable
    {
        yield ChoiceField::new('status', 'Status')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, NotificationLog $l) => ['chip' => NotificationLogStatus::SUCCESS === $l->getStatus()
                ? ['label' => 'SENT', 'color' => 'positive']
                : ['label' => 'FAILED', 'color' => 'danger']]);

        yield TextField::new('messageType', 'Type')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, NotificationLog $l) => ['text' => $l->getMessageType(), 'tone' => 'dim']);

        yield TextField::new('summary', 'Summary')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, NotificationLog $l) => [
                'text'    => $l->getSummary(),
                'sub'     => $l->getErrorMessage(),
                'subTone' => 'danger',
            ]);

        yield DateTimeField::new('createdAt', 'Sent')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, NotificationLog $l) => [
                'text' => $this->bmc->ago($l->getCreatedAt()),
                'sub'  => $this->bmc->dateTime($l->getCreatedAt()),
            ]);
    }

    /**
     * The message types actually present, rather than a hardcoded list.
     *
     * New push types get added over time; reading them off the data means the
     * facet cannot go stale.
     *
     * @return array<string, string>
     */
    private function messageTypeChoices(): array
    {
        $rows = $this->em->createQuery(
            'SELECT DISTINCT n.messageType FROM ' . NotificationLog::class . ' n ORDER BY n.messageType ASC'
        )->getScalarResult();

        $choices = [];
        foreach ($rows as $row) {
            $type = $row['messageType'];
            // "RoundReminderPush" reads better in a narrow rail as
            // "Round Reminder".
            $label = trim(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', preg_replace('/Push$/', '', $type)));
            $choices[$label ?: $type] = $type;
        }

        return $choices;
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

        yield IdField::new('id')->hideOnIndex();
        yield ChoiceField::new('status')
            ->setChoices(self::statusChoices())
            ->renderAsBadges([
                NotificationLogStatus::SUCCESS->value => 'success',
                NotificationLogStatus::FAILED->value  => 'danger',
            ]);
        yield TextField::new('messageType', 'Type');
        yield TextField::new('summary');
        yield TextField::new('dataJsonCompact', 'Payload')
            ->onlyOnIndex()
            ->setHelp('The FCM `data` payload actually sent — exactly what the device received.');
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

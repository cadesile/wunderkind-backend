<?php

declare(strict_types=1);

namespace App\Controller\Admin\Batch;

use App\Admin\BatchSelection;
use App\Admin\CsvExporter;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * The batch actions every redesigned list page shares.
 *
 * Only the pieces that need the controller itself live here — building the
 * index query for "all matching" needs createIndexQueryBuilder() and the
 * FilterFactory out of the controller's own service container, neither of
 * which an outside service can reach. The rest is in App\Admin\BatchSelection
 * and App\Admin\CsvExporter.
 *
 * A using controller must expose BatchSelection, CsvExporter and a
 * CsrfTokenManagerInterface as $batchSelection, $csvExporter and
 * $batchCsrf properties.
 */
trait BmcBatchActionsTrait
{
    /** The EXPORT CSV action every page carries. */
    protected static function exportCsvAction(): Action
    {
        return Action::new('exportCsv', 'Export CSV')
            ->linkToCrudAction('exportCsv')
            ->createAsBatchAction();
    }

    // EasyAdmin 5.7 will not route a custom CRUD action without this, and POST
    // only: an export that runs on GET is one an operator can trigger by
    // pasting a link.
    #[AdminRoute(path: '/batch/export-csv', name: 'batch_export_csv', options: ['methods' => ['POST']])]
    public function exportCsv(BatchActionDto $batch, AdminContext $context): Response
    {
        $this->assertBatchToken($batch);

        return $this->csvExporter->stream(
            $batch->getEntityFqcn(),
            $this->batchSelection->chunks($batch, $context->getRequest(), $this->matchingQuery($context)),
            CsvExporter::filename(self::exportNoun($context, $batch)),
        );
    }

    /**
     * The filename stem, e.g. "clubs" in clubs-2026-11-06.csv.
     *
     * The CRUD label is TranslatableInterface as often as it is a string, and
     * a filename is no place to resolve a translation anyway — a file landing
     * in someone's downloads should be named the same whoever exported it. So
     * a plain string label is used as-is and anything else falls back to the
     * entity's own class name.
     */
    private static function exportNoun(AdminContext $context, BatchActionDto $batch): string
    {
        $label = $context->getCrud()?->getEntityLabelInPlural();

        if (\is_string($label) && '' !== trim($label)) {
            return strtolower(str_replace(' ', '-', trim($label)));
        }

        $short = substr((string) strrchr('\\' . $batch->getEntityFqcn(), '\\'), 1);

        return strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', '-', $short) ?? 'export');
    }

    /**
     * The index query behind "select all N matching", or null when the
     * operator ticked individual rows.
     */
    protected function matchingQuery(AdminContext $context): ?QueryBuilder
    {
        $fields = new FieldCollection($this->configureFields(Crud::PAGE_INDEX));

        return $this->batchSelection->matching(
            $context,
            $context->getRequest(),
            fn () => $this->container->get(FilterFactory::class)
                ->create($context->getCrud()->getFiltersConfig(), $fields, $context->getEntity()),
            fn ($search, $filters) => $this->createIndexQueryBuilder($search, $context->getEntity(), $fields, $filters),
        );
    }

    /**
     * EasyAdmin validates the batch token inside its own batchDelete and
     * nowhere else, so a custom handler has to do it itself. The token is
     * scoped to the action name and the entity, so a token minted for Export
     * cannot be replayed against Delete.
     */
    protected function assertBatchToken(BatchActionDto $batch): void
    {
        $id = 'ea-batch-action-' . $batch->getName() . '-' . $batch->getEntityFqcn();

        if (!$this->isCsrfTokenValid($id, $batch->getCsrfToken())) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid CSRF token for this batch action.');
        }
    }

    /**
     * Back to the listing the action was launched from, with a flash that the
     * shell turns into a toast.
     */
    protected function backToReferrer(AdminContext $context, string $type, string $message): Response
    {
        $this->addFlash($type, $message);

        $referrer = (string) $context->getRequest()->request->get('referrer', '');

        return $this->redirect('' !== $referrer
            ? $referrer
            : $this->container->get(\EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface::class)
                ->setAction(Action::INDEX)
                ->generateUrl());
    }
}

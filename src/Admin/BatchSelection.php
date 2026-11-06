<?php

declare(strict_types=1);

namespace App\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use Symfony\Component\HttpFoundation\Request;

/**
 * Which rows a batch action should act on.
 *
 * Usually that is just the ids EasyAdmin posted. The exception is the design's
 * "select all N matching", offered once every row on the page is ticked:
 * EasyAdmin only ever posts ids it can see, so acting on the whole filtered set
 * means rebuilding the index query.
 *
 * That rebuild happens here, on the server, from the filters in the referrer —
 * never from a list of ids the browser sends. Two reasons: a filtered set can
 * run to thousands of rows, and more importantly a client that could enumerate
 * the set could also widen it.
 *
 * Rows come back in chunks, with the identity map cleared between them, so a
 * large selection does not accumulate in memory.
 */
final class BatchSelection
{
    public const CHUNK = 500;

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function isAllMatching(Request $request): bool
    {
        return '1' === $request->request->get('allMatching');
    }

    /**
     * How many rows the action will touch, without loading any of them.
     */
    public function count(BatchActionDto $batch, Request $request, ?QueryBuilder $matching): int
    {
        if (!$this->isAllMatching($request) || null === $matching) {
            return \count($batch->getEntityIds());
        }

        return (int) (clone $matching)
            ->select('COUNT(entity.id)')
            ->resetDQLPart('orderBy')
            ->setFirstResult(0)
            ->setMaxResults(null)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The selected entities, in chunks.
     *
     * @return iterable<int, array<object>>
     */
    public function chunks(BatchActionDto $batch, Request $request, ?QueryBuilder $matching): iterable
    {
        if (!$this->isAllMatching($request) || null === $matching) {
            foreach (array_chunk($batch->getEntityIds(), self::CHUNK) as $ids) {
                yield $this->em->getRepository($batch->getEntityFqcn())->findBy(['id' => $ids]);
            }

            return;
        }

        $offset = 0;
        while (true) {
            $rows = (clone $matching)
                ->setFirstResult($offset)
                ->setMaxResults(self::CHUNK)
                ->getQuery()
                ->getResult();

            if ([] === $rows) {
                return;
            }

            yield $rows;
            $offset += self::CHUNK;
        }
    }

    /**
     * Rebuild the listing the operator was looking at.
     *
     * The POST carries no query string of its own, so the filters come from
     * the `referrer` field the toolbar submits.
     *
     * Applying them is more invasive than it looks. EasyAdmin builds its
     * filters form inside EntityRepository::addFilterClause() from the
     * *ambient* request — AdminContextProvider's, not the SearchDto's — and
     * skips every filter unless that form is submitted, which for a form
     * declared as GET means the request must be a GET carrying the filter
     * parameters. Handing createIndexQueryBuilder() a different SearchDto
     * therefore applies the search term but silently drops every filter, and
     * "all matching" quietly becomes "all rows" — the worst possible failure
     * for something that can delete.
     *
     * So the live request is made to look like the index request for exactly
     * as long as the query builder is being assembled, then put back. The
     * alternative — reimplementing EasyAdmin's filter binding here — would
     * duplicate the form coercion and validation that decides what a filter
     * even means, and would drift from it.
     *
     * Returns null when the operator ticked individual rows, in which case
     * callers use the posted ids.
     *
     * @param callable(): FilterCollection       $buildFilters
     * @param callable(SearchDto, FilterCollection): QueryBuilder $build
     */
    public function matching(
        AdminContext $context,
        Request $request,
        callable $buildFilters,
        callable $build,
    ): ?QueryBuilder {
        if (!$this->isAllMatching($request)) {
            return null;
        }

        $referrer = (string) $request->request->get('referrer', '');
        $query    = [];
        if ('' !== $referrer) {
            parse_str((string) parse_url($referrer, \PHP_URL_QUERY), $query);
        }

        $originalQuery  = $request->query->all();
        $originalMethod = $request->getMethod();

        $request->query->replace($originalQuery + $query);
        $request->setMethod('GET');

        try {
            $search = new SearchDto(
                $request,
                $context->getCrud()?->getSearchFields(),
                \is_string($query['query'] ?? null) ? $query['query'] : null,
                $context->getCrud()?->getDefaultSort() ?? [],
                [],
                // An empty map has to be null: EasyAdmin reads [] as "filters
                // were applied and matched nothing" rather than "no filtering".
                [] === ($query['filters'] ?? []) ? null : $query['filters'],
            );

            return $build($search, $buildFilters());
        } finally {
            $request->query->replace($originalQuery);
            $request->setMethod($originalMethod);
        }
    }
}

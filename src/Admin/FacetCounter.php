<?php

declare(strict_types=1);

namespace App\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;

/**
 * Per-option match counts for the filter rail.
 *
 * The rule that makes a facet rail usable: each option is counted with every
 * OTHER active filter applied, but not with its own facet's. Ticking "England"
 * must not set every other country to 0 — you still need to see that Scotland
 * has 104 so you can switch to it. So each facet gets its own query, built from
 * a SearchDto with just that facet removed.
 *
 * That is one grouped query per facet, which is why count() takes them all at
 * once and why callers on large tables should cache the result (the counts
 * only change as fast as the data does).
 */
final class FacetCounter
{
    /**
     * @param array<string, string> $expressions facet key => DQL expression to
     *                                           group on, e.g. 'entity.country'
     *                                           or a CASE for a derived bucket
     * @param array<string, mixed>  $parameters  bindings any expression needs
     * @param array<string, string>  $joins       alias => association path, for
     *                                            a facet that groups on a
     *                                            related entity. Required
     *                                            there: removing the facet's
     *                                            own filter also removes the
     *                                            join that filter was creating,
     *                                            so the expression would
     *                                            otherwise reference an alias
     *                                            that no longer exists.
     *
     * @return array<string, array<string, int>> facet key => value => count
     */
    public function count(
        AbstractCrudController $controller,
        AdminContext $context,
        FieldCollection $fields,
        FilterCollection $filters,
        array $expressions,
        array $parameters = [],
        array $joins = [],
    ): array {
        $counts = [];

        foreach ($expressions as $key => $expression) {
            $counts[$key] = $this->countOne($controller, $context, $fields, $filters, $key, $expression, $parameters, $joins);
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function countOne(
        AbstractCrudController $controller,
        AdminContext $context,
        FieldCollection $fields,
        FilterCollection $filters,
        string $key,
        string $expression,
        array $parameters,
        array $joins,
    ): array {
        $search = $this->searchWithout($context->getSearch(), $key);

        // FilterCollection is mutable and shared with the page's own query, so
        // clone before unsetting — otherwise counting a facet would quietly
        // remove it from the real listing too.
        $without = clone $filters;
        $without->offsetUnset(str_replace(':', '.', $key));

        $qb = $controller->createIndexQueryBuilder($search, $context->getEntity(), $fields, $without);

        foreach ($joins as $alias => $path) {
            // Left, not inner: a club with no current league still belongs in
            // every other facet's counts, and an inner join would drop it.
            if (str_contains($expression, $alias . '.')) {
                $qb->leftJoin($path, $alias);
            }
        }

        $qb->select(\sprintf('%s AS v', $expression), 'COUNT(entity.id) AS n')
            ->groupBy('v')
            ->resetDQLPart('orderBy')
            // An index query builder may carry paging; counting must not.
            ->setFirstResult(0)
            ->setMaxResults(null);

        foreach ($parameters as $name => $value) {
            if (str_contains($expression, ':' . $name)) {
                $qb->setParameter($name, $value);
            }
        }

        $counts = [];
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            // Group keys reach the rail as strings: the template compares them
            // against checkbox values, which are always strings.
            $counts[$this->key($row['v'])] = (int) $row['n'];
        }

        return $counts;
    }

    private function searchWithout(?SearchDto $search, string $key): ?SearchDto
    {
        if (null === $search) {
            return null;
        }

        $applied = $search->getAppliedFilters() ?? [];
        unset($applied[$key]);

        // SearchDto is immutable with no setters, so rebuild it. Keeping the
        // query term matters: the search box narrows every facet count.
        return new SearchDto(
            $search->getRequest(),
            $search->getSearchableProperties(),
            $search->getQuery(),
            $search->getDefaultSort(),
            $search->getCustomSort(),
            [] === $applied ? null : $applied,
            $search->getSearchMode(),
        );
    }

    private function key(mixed $value): string
    {
        return match (true) {
            null === $value          => '',
            \is_bool($value)         => $value ? '1' : '0',
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum   => $value->name,
            default                  => (string) $value,
        };
    }
}

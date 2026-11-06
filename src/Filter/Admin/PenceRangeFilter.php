<?php

declare(strict_types=1);

namespace App\Filter\Admin;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\NumericFilterType;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A money range typed in pounds against a column stored in pence.
 *
 * Every money column in this schema is an integer number of pence, but nobody
 * filters a balance by typing 480000. The rail labels these fields with a £ and
 * this multiplies by 100 on the way into the query, so "−48" to "6200" means
 * what it looks like it means.
 *
 * Fractional pounds are honoured (0.5 → 50p) and then rounded, so a stray
 * decimal cannot produce a non-integer bound against an integer column.
 */
final class PenceRangeFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(string $propertyName, TranslatableInterface|string|false|null $label = null): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(NumericFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle');
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $property   = $filterDataDto->getEntityAlias() . '.' . $filterDataDto->getProperty();
        $comparison = $filterDataDto->getComparison();

        $from = self::toPence($filterDataDto->getValue());
        $to   = self::toPence($filterDataDto->getValue2());

        // "between" with only one side filled is still a useful half-open
        // range; EasyAdmin's own NumericFilter would emit a broken BETWEEN.
        if (ComparisonType::BETWEEN === $comparison) {
            if (null !== $from && null !== $to) {
                $queryBuilder
                    ->andWhere(\sprintf('%s BETWEEN :%s AND :%s', $property, $filterDataDto->getParameterName(), $filterDataDto->getParameter2Name()))
                    ->setParameter($filterDataDto->getParameterName(), $from)
                    ->setParameter($filterDataDto->getParameter2Name(), $to);

                return;
            }

            if (null !== $from) {
                $queryBuilder
                    ->andWhere(\sprintf('%s >= :%s', $property, $filterDataDto->getParameterName()))
                    ->setParameter($filterDataDto->getParameterName(), $from);
            }
            if (null !== $to) {
                $queryBuilder
                    ->andWhere(\sprintf('%s <= :%s', $property, $filterDataDto->getParameter2Name()))
                    ->setParameter($filterDataDto->getParameter2Name(), $to);
            }

            return;
        }

        if (null === $from) {
            return;
        }

        $queryBuilder
            ->andWhere(\sprintf('%s %s :%s', $property, $comparison, $filterDataDto->getParameterName()))
            ->setParameter($filterDataDto->getParameterName(), $from);
    }

    private static function toPence(mixed $pounds): ?int
    {
        if (null === $pounds || '' === $pounds) {
            return null;
        }

        return (int) round(((float) $pounds) * 100);
    }
}

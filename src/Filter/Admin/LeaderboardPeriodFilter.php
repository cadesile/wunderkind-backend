<?php

declare(strict_types=1);

namespace App\Filter\Admin;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\ChoiceFilterType;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * All-time boards versus weekly ones.
 *
 * LeaderboardEntry::$period is a string holding either the literal "all-time"
 * or an ISO week like "2026-W34". There are as many weekly values as there
 * have been weeks, so offering them as choices would be a list that grows
 * forever; the useful distinction is just the two kinds.
 */
final class LeaderboardPeriodFilter implements FilterInterface
{
    use FilterTrait;

    public const ALL_TIME = 'all-time';
    public const WEEKLY   = 'weekly';

    public static function new(
        string $propertyName = 'period',
        TranslatableInterface|string|false|null $label = 'Period',
    ): self {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('value_type_options', [
                'multiple' => true,
                'choices'  => ['All-time' => self::ALL_TIME, 'Weekly' => self::WEEKLY],
            ]);
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $selected = array_filter((array) $filterDataDto->getValue(), static fn ($v) => null !== $v && '' !== $v);

        if ([] === $selected || \count($selected) >= 2) {
            return;
        }

        $property = $filterDataDto->getEntityAlias() . '.' . $filterDataDto->getProperty();
        $param    = 'bmc_period_' . $filterDataDto->getParameterName();

        $queryBuilder
            ->andWhere(\sprintf(
                '%s %s :%s',
                $property,
                \in_array(self::ALL_TIME, $selected, true) ? '=' : '!=',
                $param
            ))
            ->setParameter($param, self::ALL_TIME);
    }

    public static function countExpression(string $property = 'entity.period'): string
    {
        return \sprintf("CASE WHEN %s = '%s' THEN '%s' ELSE '%s' END", $property, self::ALL_TIME, self::ALL_TIME, self::WEEKLY);
    }
}

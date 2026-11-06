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
 * Still in the market pool, or already attached to a club.
 *
 * Investors and sponsors both say this the same way — Investor::isInMarketPool()
 * and Sponsor::isInMarketPool() are both "club is null" — so the distinction
 * has no column of its own and an association filter cannot express it: you
 * cannot pick "no club" from a list of clubs.
 *
 * It is the first thing worth asking on either page, since an unassigned
 * company is one the game can still hand out.
 */
final class MarketPoolFilter implements FilterInterface
{
    use FilterTrait;

    public const POOL     = 'pool';
    public const ASSIGNED = 'assigned';

    public static function new(
        string $propertyName = 'club',
        TranslatableInterface|string|false|null $label = 'Availability',
    ): self {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('value_type_options', [
                'multiple' => true,
                'choices'  => ['In pool' => self::POOL, 'Assigned' => self::ASSIGNED],
            ]);
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $selected = array_filter((array) $filterDataDto->getValue(), static fn ($v) => null !== $v && '' !== $v);

        // Both ticked is the same as neither: every row is one or the other.
        if ([] === $selected || \count($selected) >= 2) {
            return;
        }

        $property = $filterDataDto->getEntityAlias() . '.' . $filterDataDto->getProperty();

        $queryBuilder->andWhere(\sprintf(
            '%s IS %s NULL',
            $property,
            \in_array(self::POOL, $selected, true) ? '' : 'NOT',
        ));
    }

    public static function countExpression(string $property = 'entity.club'): string
    {
        return \sprintf("CASE WHEN %s IS NULL THEN '%s' ELSE '%s' END", $property, self::POOL, self::ASSIGNED);
    }
}

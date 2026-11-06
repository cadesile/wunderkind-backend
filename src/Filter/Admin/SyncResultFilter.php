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
 * What happened to a sync: accepted, rolled back, or rejected.
 *
 * The design asked for four buckets — valid, week rollback, duplicate week and
 * failed validation — but only the first two are things the schema records.
 * SyncRecord has isValid and isRollback as columns; the reason a rejected sync
 * was rejected is free text in invalidReason, with no enum behind it, so
 * "duplicate week" and "failed validation" cannot be told apart reliably.
 * Rather than guess at substrings, rejected stays one bucket and the row's own
 * cell shows the reason text.
 */
final class SyncResultFilter implements FilterInterface
{
    use FilterTrait;

    public const VALID    = 'valid';
    public const ROLLBACK = 'rollback';
    public const REJECTED = 'rejected';

    public static function new(
        string $propertyName = 'isValid',
        TranslatableInterface|string|false|null $label = 'Result',
    ): self {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('value_type_options', [
                'multiple' => true,
                'choices'  => [
                    'Valid'         => self::VALID,
                    'Week rollback' => self::ROLLBACK,
                    'Rejected'      => self::REJECTED,
                ],
            ]);
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $selected = array_filter((array) $filterDataDto->getValue(), static fn ($v) => null !== $v && '' !== $v);

        if ([] === $selected || \count($selected) >= 3) {
            return;
        }

        $alias = $filterDataDto->getEntityAlias();
        $or    = $queryBuilder->expr()->orX();

        foreach ($selected as $result) {
            match ($result) {
                // A rollback is still a valid sync, so the two overlap and
                // "valid" has to exclude it or the buckets double-count.
                self::VALID    => $or->add("($alias.isValid = true AND $alias.isRollback = false)"),
                self::ROLLBACK => $or->add("$alias.isRollback = true"),
                self::REJECTED => $or->add("$alias.isValid = false"),
                default        => null,
            };
        }

        if ($or->count() > 0) {
            $queryBuilder->andWhere($or);
        }
    }

    public static function countExpression(string $alias = 'entity'): string
    {
        return \sprintf(
            "CASE WHEN %s.isValid = false THEN '%s' WHEN %s.isRollback = true THEN '%s' ELSE '%s' END",
            $alias, self::REJECTED, $alias, self::ROLLBACK, self::VALID
        );
    }
}

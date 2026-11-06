<?php

declare(strict_types=1);

namespace App\Filter\Admin;

use App\Entity\Club;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\ChoiceFilterType;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Users with no clubs, one club, or two or more.
 *
 * Three buckets rather than a numeric range because that is how the question
 * actually gets asked: who signed up and never started a club, and who is
 * running several. A correlated count subquery, so it needs no join and
 * cannot multiply rows.
 */
final class ClubsOwnedFilter implements FilterInterface
{
    use FilterTrait;

    public const NONE = '0';
    public const ONE  = '1';
    public const MANY = '2+';

    public static function new(
        string $propertyName = 'id',
        TranslatableInterface|string|false|null $label = 'Clubs owned',
    ): self {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('value_type_options', [
                'multiple' => true,
                'choices'  => ['None' => self::NONE, 'One' => self::ONE, '2+' => self::MANY],
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
        $n     = 0;

        foreach ($selected as $bucket) {
            // A fresh subquery alias each time: all of these land in one DQL
            // statement, and repeating "FROM Club bmc_c" is a semantical error.
            $count = self::countSubquery($queryBuilder, $alias, 'bmc_owned_' . $n++);
            match ($bucket) {
                self::NONE => $or->add(\sprintf('(%s) = 0', $count)),
                self::ONE  => $or->add(\sprintf('(%s) = 1', $count)),
                self::MANY => $or->add(\sprintf('(%s) >= 2', $count)),
                default    => null,
            };
        }

        if ($or->count() > 0) {
            $queryBuilder->andWhere($or);
        }
    }

    public static function countExpression(QueryBuilder $queryBuilder, string $alias = 'entity'): string
    {
        return \sprintf(
            "CASE WHEN (%s) = 0 THEN '%s' WHEN (%s) = 1 THEN '%s' ELSE '%s' END",
            self::countSubquery($queryBuilder, $alias, 'bmc_facet_0'), self::NONE,
            self::countSubquery($queryBuilder, $alias, 'bmc_facet_1'), self::ONE,
            self::MANY
        );
    }

    private static function countSubquery(QueryBuilder $queryBuilder, string $alias, string $subAlias): string
    {
        return $queryBuilder->getEntityManager()->createQueryBuilder()
            ->select(\sprintf('COUNT(%s.id)', $subAlias))
            ->from(Club::class, $subAlias)
            ->where(\sprintf('%s.user = %s', $subAlias, $alias))
            ->getDQL();
    }
}

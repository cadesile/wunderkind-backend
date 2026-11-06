<?php

declare(strict_types=1);

namespace App\Filter\Admin;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\ChoiceFilterType;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Real / guest / spoof accounts.
 *
 * There is no account-type column. The distinction lives in the email domain
 * — User::isGuestEmail() and isSpoofEmail() are the authority — so this filter
 * is a LIKE against the same two constants rather than anything a plain
 * ChoiceFilter could express.
 *
 * (Club::$isSpoof is a different thing entirely: a spoof *club*, which a real
 * user can own.)
 */
final class AccountTypeFilter implements FilterInterface
{
    use FilterTrait;

    public const REAL  = 'real';
    public const GUEST = 'guest';
    public const SPOOF = 'spoof';

    public static function new(
        string $propertyName = 'email',
        TranslatableInterface|string|false|null $label = 'Account type',
    ): self {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('value_type_options', [
                'multiple' => true,
                'choices'  => ['Real' => self::REAL, 'Guest' => self::GUEST, 'Spoof' => self::SPOOF],
            ]);
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $selected = array_filter((array) $filterDataDto->getValue(), static fn ($v) => null !== $v && '' !== $v);

        if ([] === $selected || \count($selected) >= 3) {
            return;
        }

        $email  = $filterDataDto->getEntityAlias() . '.email';
        $suffix = $filterDataDto->getParameterName();
        $or     = $queryBuilder->expr()->orX();
        $params = [];

        foreach ($selected as $type) {
            switch ($type) {
                case self::GUEST:
                    $or->add(\sprintf('%s LIKE :bmc_guest_%s', $email, $suffix));
                    $params['bmc_guest_' . $suffix] = '%' . User::GUEST_EMAIL_DOMAIN;
                    break;
                case self::SPOOF:
                    $or->add(\sprintf('%s LIKE :bmc_spoof_%s', $email, $suffix));
                    $params['bmc_spoof_' . $suffix] = '%' . User::SPOOF_EMAIL_DOMAIN;
                    break;
                case self::REAL:
                    // Real is defined by exclusion, so it is the negation of
                    // both — not a domain of its own.
                    $or->add(\sprintf('(%s NOT LIKE :bmc_nguest_%s AND %s NOT LIKE :bmc_nspoof_%s)', $email, $suffix, $email, $suffix));
                    $params['bmc_nguest_' . $suffix] = '%' . User::GUEST_EMAIL_DOMAIN;
                    $params['bmc_nspoof_' . $suffix] = '%' . User::SPOOF_EMAIL_DOMAIN;
                    break;
            }
        }

        if (0 === $or->count()) {
            return;
        }

        $queryBuilder->andWhere($or);
        foreach ($params as $name => $value) {
            $queryBuilder->setParameter($name, $value);
        }
    }

    /** The same buckets as a DQL CASE, so FacetCounter agrees with apply(). */
    public static function countExpression(string $emailProperty = 'entity.email'): string
    {
        return \sprintf(
            "CASE WHEN %s LIKE '%%%s' THEN '%s' WHEN %s LIKE '%%%s' THEN '%s' ELSE '%s' END",
            $emailProperty, User::GUEST_EMAIL_DOMAIN, self::GUEST,
            $emailProperty, User::SPOOF_EMAIL_DOMAIN, self::SPOOF,
            self::REAL,
        );
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\League;
use App\Enum\CompanySize;
use App\Enum\ReputationTier;
use App\Enum\TrophyColour;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class LeagueCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return League::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['country' => 'ASC', 'tier' => 'ASC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('country'))
            ->add(ChoiceFilter::new('tier')->setChoices(array_combine(
                array_map(fn($t) => "Tier $t", range(1, 8)),
                range(1, 8)
            )))
            ->add(TextFilter::new('name'))
            ->add(ChoiceFilter::new('leagueReputationTier')->setChoices([
                'Local'    => ReputationTier::LOCAL,
                'Regional' => ReputationTier::REGIONAL,
                'National' => ReputationTier::NATIONAL,
                'Elite'    => ReputationTier::ELITE,
            ]))
            ->add(NumericFilter::new('promotionSpots'))
            ->add(NumericFilter::new('tvDeal', 'TV Deal'))
            ->add(NumericFilter::new('prizeMoney', 'Prize Money'))
            ->add(NumericFilter::new('leaguePositionPot', 'Position Pot'));
    }

    public function configureFields(string $pageName): iterable
    {
        /** @var League|null $entity */
        $entity  = $this->getContext()?->getEntity()?->getInstance();
        $isTier1 = $entity instanceof League && $entity->getTier() === 1;

        yield IdField::new('id')->hideOnForm();

        yield TextField::new('country')
            ->setHelp('ISO 2-letter code, e.g. ES, EN, DE');

        yield IntegerField::new('tier')
            ->setHelp('1 (top) to 8 (bottom)');

        yield TextField::new('name')
            ->setHelp('Default "League {tier}", overridable e.g. "Premier League"');

        yield ChoiceField::new('leagueReputationTier')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions([
                'class'       => ReputationTier::class,
                'required'    => false,
                'placeholder' => '-- Not set --',
            ])
            ->hideOnIndex()
            ->setHelp('Determines which sponsor sizes are available (Local=Small, Regional=Small+Medium, National=Medium+Large, Elite=Large)');

        yield NumberField::new('promotionSpots')
            ->setRequired(false)
            ->hideOnIndex()
            ->setFormTypeOption('disabled', $isTier1)
            ->setHelp($isTier1
                ? 'Tier 1 — no promotion available.'
                : 'Number of teams promoted per season. Leave empty if not configured.');

        yield IntegerField::new('tvDeal', 'TV Deal')
            ->setFormType(MoneyType::class)
            ->setFormTypeOptions(['currency' => 'GBP', 'divisor' => 100])
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Per-season income divided equally among all clubs.');

        yield IntegerField::new('prizeMoney', 'Prize Money')
            ->setFormType(MoneyType::class)
            ->setFormTypeOptions(['currency' => 'GBP', 'divisor' => 100])
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Paid in full to the league winner.');

        yield IntegerField::new('leaguePositionPot', 'Position Pot')
            ->setFormType(MoneyType::class)
            ->setFormTypeOptions(['currency' => 'GBP', 'divisor' => 100])
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Total pot distributed by finishing position. 1st gets most; each lower position gets leaguePositionDecreasePercent% less.');

        yield ChoiceField::new('trophyImage', 'Trophy Design')
            ->setChoices(array_combine(
                array_map(fn ($n) => "Trophy $n", range(1, 15)),
                array_map(fn ($n) => "trophy-$n", range(1, 15))
            ))
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Select the trophy silhouette. A live preview appears below once you choose.');

        yield ChoiceField::new('trophyColour', 'Trophy Colour')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions([
                'class'       => TrophyColour::class,
                'required'    => false,
                'placeholder' => '-- Not set --',
            ])
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Gold, Silver, or Gold & Silver — applied by the frontend when rendering the trophy.');

        yield AssociationField::new('sponsors')
            ->setFormTypeOption('by_reference', false)
            ->hideOnIndex()
            ->setHelp('Sponsors attached to this league. Filtered by league reputation tier when set.')
            ->setQueryBuilder(function (QueryBuilder $qb) use ($entity): QueryBuilder {
                $qb->andWhere('entity.isActive = true');

                if ($entity instanceof League && $entity->getLeagueReputationTier() !== null) {
                    $sizes = match ($entity->getLeagueReputationTier()) {
                        ReputationTier::LOCAL    => [CompanySize::SMALL->value],
                        ReputationTier::REGIONAL => [CompanySize::SMALL->value, CompanySize::MEDIUM->value],
                        ReputationTier::NATIONAL => [CompanySize::MEDIUM->value, CompanySize::LARGE->value],
                        ReputationTier::ELITE    => [CompanySize::LARGE->value],
                    };
                    $qb->andWhere('entity.size IN (:sizes)')->setParameter('sizes', $sizes);
                }

                return $qb;
            });

        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}

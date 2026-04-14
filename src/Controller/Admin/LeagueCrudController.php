<?php

namespace App\Controller\Admin;

use App\Entity\League;
use App\Enum\CompanySize;
use App\Enum\ReputationTier;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
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

        yield NumberField::new('tvDeal')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('TV deal income per season in pence (e.g. 500000 = £5,000). Divided equally among all clubs.');

        yield NumberField::new('prizeMoney')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Prize paid in full to the league winner (pence).');

        yield NumberField::new('leaguePositionPot')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Total pot distributed by finishing position (pence). 1st gets most; each lower position gets leaguePositionDecreasePercent% less.');

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

<?php

namespace App\Controller\Admin;

use App\Entity\League;
use App\Enum\CompanySize;
use App\Enum\ReputationTier;
use App\Form\Type\TrophyConfigType;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
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
        return $crud
            ->setDefaultSort(['country' => 'ASC', 'tier' => 'ASC'])
            // Custom widget for the compound `trophy` json field — see the
            // EasyAdmin-form-theme note in CLAUDE.md's Key Gotchas.
            ->addFormTheme('admin/form/trophy_config_theme.html.twig');
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

        yield Field::new('trophy', 'Trophy')
            ->setFormType(TrophyConfigType::class)
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp(
                'The trophy sprite\'s own config — design, finish and ribbons — stored verbatim and '
                . 'drawn by the same component the app uses. Switch "Awards a trophy" off to award none.'
            );

        yield IntegerField::new('sponsorCount', 'Sponsor Count')
            ->setHelp('Number of sponsors to source for this league during world cache generation. Sponsors are filtered by league reputation tier.')
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}

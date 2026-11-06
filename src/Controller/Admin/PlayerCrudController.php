<?php

namespace App\Controller\Admin;

use App\Admin\FacetCounter;
use App\Entity\Player;
use App\Enum\Country;
use App\Filter\Admin\AgeRangeFilter;
use App\Filter\Admin\PenceRangeFilter;
use App\Twig\BmcAdminExtension;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use App\Entity\PersonalityProfile;
use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
use App\Enum\RecruitmentSource;
use App\Form\Type\AppearanceType;
use App\Form\Type\ArchetypeSummaryType;
use App\Repository\PlayerRepository;
use App\Service\ArchetypeResolverService;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\HttpFoundation\Response;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class PlayerCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PlayerRepository $playerRepository,
        private readonly ArchetypeResolverService $archetypeResolver,
        private readonly FacetCounter $facets,
        private readonly BmcAdminExtension $bmc,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Player::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a
                ->setLabel('View player')->setIcon('eye')
                ->setHtmlAttributes(['title' => 'View player', 'aria-label' => 'View player'])
                ->setCssClass('bmc-iconbtn'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a
                ->setLabel('Edit player')->setIcon('pencil')
                ->setHtmlAttributes(['title' => 'Edit player', 'aria-label' => 'Edit player'])
                ->setCssClass('bmc-iconbtn'))
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->disable(Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Player')
            ->setEntityLabelInPlural('Players')
            ->setDefaultSort(['lastName' => 'ASC'])
            ->setPaginatorPageSize(25)
            ->showEntityActionsInlined()
            ->setSearchFields(['firstName', 'lastName', 'nationality'])
            // Was admin/player_index.html.twig, which replaced
            // content_header_wrapper with a three-card summary and so would
            // have swallowed the new page strip. The shared template owns that
            // block now.
            ->overrideTemplate('crud/index', 'admin/crud/bmc_index.html.twig')
            ->addFormTheme('admin/form/appearance_theme.html.twig')
            ->addFormTheme('admin/form/archetype_summary_theme.html.twig');
    }

    // The playerSummary panel that used to sit here is gone with
    // admin/player_index.html.twig. It was three cards of by-nationality and
    // by-position totals rendered above the table — the filter rail now
    // carries the same breakdown as live facet counts beside each option,
    // which is both current and clickable.

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('position', 'Position')->setChoices([
                'Goalkeeper' => PlayerPosition::GOALKEEPER,
                'Defender'   => PlayerPosition::DEFENDER,
                'Midfielder' => PlayerPosition::MIDFIELDER,
                'Attacker'   => PlayerPosition::ATTACKER,
            ])->canSelectMultiple())
            ->add(ChoiceFilter::new('status', 'Status')->setChoices([
                'Active'               => PlayerStatus::ACTIVE,
                'Loaned Out'           => PlayerStatus::LOANED_OUT,
                'Transferred'          => PlayerStatus::TRANSFERRED,
                'Transferred (Agent)'  => PlayerStatus::TRANSFERRED_VIA_AGENT,
                'Retired'              => PlayerStatus::RETIRED,
            ])->canSelectMultiple())
            // Every case, not the four this used to list: the two it left out
            // (youth and senior intake) are where the players actually come
            // from, so the facet read 0 against all four offered options while
            // the whole roster sat in a bucket you could not tick.
            ->add(ChoiceFilter::new('recruitmentSource', 'Recruited via')
                ->setChoices($this->recruitmentChoices())
                ->canSelectMultiple())
            ->add(ChoiceFilter::new('nationality', 'Nationality')->setChoices($this->nationalityChoices())->canSelectMultiple())
            ->add(AgeRangeFilter::new())
            ->add(NumericFilter::new('currentAbility', 'Current ability'))
            ->add(NumericFilter::new('potential', 'Potential'))
            ->add(PenceRangeFilter::new('contractValue', 'Wage/wk'));
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $responseParameters->set('bmcGroup', 'People & market');
        $responseParameters->set('bmcDomain', 'people');
        $responseParameters->set('bmcTotal', $this->em->getRepository(Player::class)->count([]));
        $responseParameters->set('bmcSearchPlaceholder', 'Player name');
        // One column: "Goalkeeper" plus its count does not fit a 136px half-rail.
        $responseParameters->set('bmcFacetColumns', []);
        $responseParameters->set('bmcUnits', ['contractValue' => '£']);
        $responseParameters->set('bmcWidths', [
            'position' => 80, 'currentAbility' => 210, 'status' => 132, 'contractValue' => 96,
        ]);

        $responseParameters->set('bmcFacets', $this->facets->count(
            $this,
            $this->getContext(),
            new FieldCollection(iterator_to_array($this->indexFields())),
            $responseParameters->get('filters'),
            [
                'position'          => 'entity.position',
                'status'            => 'entity.status',
                'recruitmentSource' => 'entity.recruitmentSource',
                'nationality'       => 'entity.nationality',
            ],
        ));

        return $responseParameters;
    }

    /** @return array<string, RecruitmentSource> */
    private function recruitmentChoices(): array
    {
        $choices = [];
        foreach (RecruitmentSource::cases() as $case) {
            $choices[ucwords(str_replace('_', ' ', $case->value))] = $case;
        }

        return $choices;
    }

    /** @return array<string, string> only the nationalities actually present */
    private function nationalityChoices(): array
    {
        $rows = $this->em->createQuery(
            'SELECT DISTINCT p.nationality FROM ' . Player::class . ' p ORDER BY p.nationality ASC'
        )->getScalarResult();

        return array_combine(
            array_column($rows, 'nationality'),
            array_column($rows, 'nationality'),
        );
    }

    /**
     * Player, position, ability, status, wage.
     *
     * Ability and potential share one meter: the green fill is what the player
     * can do now and the grey ghost behind it is how far they could go, so the
     * gap between the two is the thing you actually read.
     */
    private function indexFields(): iterable
    {
        yield TextField::new('lastName', 'Player')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Player $p) => [
                // Nationality is a demonym here too, so it needs the same
                // reverse lookup the Users page uses before it can name a flag.
                'flag' => Country::fromNationality($p->getNationality())?->value,
                'text' => $p->getFullName(),
                'sub'  => $p->getNationality() . ' · ' . $p->getAge() . 'y',
            ]);

        yield TextField::new('position', 'Pos')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Player $p) => ['chip' => [
                'label' => $p->getPositionValue(),
                'color' => match ($p->getPositionValue()) {
                    'GK'    => 'warning',
                    'DEF'   => 'positive',
                    'ATT'   => 'danger',
                    default => null,
                },
            ]]);

        yield IntegerField::new('currentAbility', 'Ability · potential')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Player $p) => ['bar' => [
                'pct'   => $p->getCurrentAbility(),
                'pct2'  => $p->getPotential(),
                'label' => $p->getCurrentAbility() . ' / ' . $p->getPotential(),
            ]]);

        yield TextField::new('status', 'Status')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->formatValue(fn ($v, Player $p) => ['chip' => match ($p->getStatusValue()) {
                'active'               => ['label' => 'ACTIVE', 'color' => 'positive'],
                'loaned_out'           => ['label' => 'LOANED OUT', 'color' => 'warning'],
                'transferred'          => ['label' => 'TRANSFERRED', 'color' => null],
                'transferred_via_agent' => ['label' => 'VIA AGENT', 'color' => null],
                'retired'              => ['label' => 'RETIRED', 'color' => 'subtle'],
                default                => ['label' => strtoupper($p->getStatusValue()), 'color' => null],
            }]);

        yield IntegerField::new('contractValue', 'Wage/wk')
            ->setTemplatePath('admin/field/bmc_cell.html.twig')
            ->setTextAlign('right')
            ->formatValue(fn ($v, Player $p) => ['text' => $this->bmc->money($p->getContractValue())]);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_INDEX === $pageName) {
            yield from $this->indexFields();

            return;
        }

        // ── Index: flat list columns ──────────────────────────────────────────
        yield TextField::new('firstName')->onlyOnIndex();
        yield TextField::new('lastName')->onlyOnIndex();
        yield TextField::new('nationality')->onlyOnIndex();
        yield DateField::new('dateOfBirth', 'DOB')->setFormat('yyyy-MM-dd')->onlyOnIndex();
        yield IntegerField::new('currentAbility', 'CA')->onlyOnIndex();
        yield IntegerField::new('potential', 'PA')->onlyOnIndex();
        yield IntegerField::new('height', 'Height')->onlyOnIndex();
        yield IntegerField::new('weight', 'Weight')->onlyOnIndex();

        // ── Detail-only fields ────────────────────────────────────────────────
        yield IdField::new('id')->onlyOnDetail();

        yield IntegerField::new('overall', 'Overall')
            ->hideOnForm()
            ->hideOnIndex()
            ->setHelp('(pace+technical+vision+power+stamina+heart) / 6');

        yield DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex();

        // ── Panel: Identity ───────────────────────────────────────────────────
        yield FormField::addFieldset('Identity', 'fa fa-user')->hideOnIndex();

        yield TextField::new('firstName')->setColumns(6)->hideOnIndex();
        yield TextField::new('lastName')->setColumns(6)->hideOnIndex();
        yield DateField::new('dateOfBirth')->setFormat('yyyy-MM-dd')->setColumns(4)->hideOnIndex();
        yield TextField::new('nationality')->setColumns(4)->hideOnIndex();

        yield ChoiceField::new('position')
            ->setChoices([
                'Goalkeeper' => PlayerPosition::GOALKEEPER,
                'Defender'   => PlayerPosition::DEFENDER,
                'Midfielder' => PlayerPosition::MIDFIELDER,
                'Attacker'   => PlayerPosition::ATTACKER,
            ])
            ->renderAsBadges([
                PlayerPosition::GOALKEEPER->value => 'warning',
                PlayerPosition::DEFENDER->value   => 'success',
                PlayerPosition::MIDFIELDER->value => 'primary',
                PlayerPosition::ATTACKER->value   => 'danger',
            ])
            ->setColumns(4)
            ->hideOnIndex();

        yield ChoiceField::new('secondaryPosition', 'Secondary Position')
            ->setChoices([
                'Goalkeeper' => PlayerPosition::GOALKEEPER,
                'Defender'   => PlayerPosition::DEFENDER,
                'Midfielder' => PlayerPosition::MIDFIELDER,
                'Attacker'   => PlayerPosition::ATTACKER,
            ])
            ->renderAsBadges([
                PlayerPosition::GOALKEEPER->value => 'warning',
                PlayerPosition::DEFENDER->value   => 'success',
                PlayerPosition::MIDFIELDER->value => 'primary',
                PlayerPosition::ATTACKER->value   => 'danger',
            ])
            ->setFormTypeOption('required', false)
            ->setFormTypeOption('placeholder', 'None')
            ->setColumns(4)
            ->hideOnIndex();

        yield ChoiceField::new('status')
            ->setChoices([
                'Active'               => PlayerStatus::ACTIVE,
                'Loaned Out'           => PlayerStatus::LOANED_OUT,
                'Transferred'          => PlayerStatus::TRANSFERRED,
                'Transferred (Agent)'  => PlayerStatus::TRANSFERRED_VIA_AGENT,
                'Retired'              => PlayerStatus::RETIRED,
            ])
            ->renderAsBadges([
                PlayerStatus::ACTIVE->value                => 'success',
                PlayerStatus::LOANED_OUT->value            => 'warning',
                PlayerStatus::TRANSFERRED->value           => 'secondary',
                PlayerStatus::TRANSFERRED_VIA_AGENT->value => 'secondary',
                PlayerStatus::RETIRED->value               => 'secondary',
            ])
            ->setColumns(4)
            ->hideOnIndex();

        // ── Panel: Appearance ─────────────────────────────────────────────────
        yield FormField::addFieldset('Appearance', 'fa fa-user-circle')->hideOnIndex();

        // The previewed player's real measurements drive the sprite's build tier
        // (145-169 / 170-184 / 185-200 cm, 58-69 / 70-84 / 85-100 kg). Passed to
        // the widget rather than stored on `appearance`: Player already owns these
        // columns, so a copied-in tier would be a second source of truth that goes
        // stale the moment the player's height or weight changes. 0 is this
        // entity's "unset" for both (non-nullable int columns defaulting to 0), so
        // it maps to null and the preview falls back to the neutral medium build.
        $subject = $this->getContext()?->getEntity()?->getInstance();
        $player  = $subject instanceof Player ? $subject : null;

        yield Field::new('appearance')
            ->setFormType(AppearanceType::class)
            ->setFormTypeOptions([
                'person_type' => 'player',
                'height_cm'   => ($player?->getHeight() ?: null),
                'weight_kg'   => ($player?->getWeight() ?: null),
            ])
            ->onlyOnForms();

// ── Panel: Ability ────────────────────────────────────────────────────
        yield FormField::addFieldset('Ability', 'fa fa-chart-bar')->hideOnIndex();

        yield IntegerField::new('potential')->setColumns(4)->hideOnIndex();
        yield IntegerField::new('currentAbility', 'Current Ability')->setColumns(4)->hideOnIndex();
        yield IntegerField::new('pace')->setHelp('0–100')->setColumns(4)->hideOnIndex();
        yield IntegerField::new('technical')->setHelp('0–100')->setColumns(4)->hideOnIndex();
        yield IntegerField::new('vision')->setHelp('0–100')->setColumns(4)->hideOnIndex();
        yield IntegerField::new('power')->setHelp('0–100')->setColumns(4)->hideOnIndex();
        yield IntegerField::new('stamina')->setHelp('0–100')->setColumns(4)->hideOnIndex();
        yield IntegerField::new('heart')->setHelp('0–100')->setColumns(4)->hideOnIndex();

        // ── Panel: Personality ────────────────────────────────────────────────
        yield FormField::addFieldset('Personality', 'fa fa-brain')->hideOnIndex();

        yield IntegerField::new('personality.determination', 'Determination')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.professionalism', 'Professionalism')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.ambition', 'Ambition')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.loyalty', 'Loyalty')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.adaptability', 'Adaptability')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.pressure', 'Pressure')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.temperament', 'Temperament')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.consistency', 'Consistency')->setHelp('1–20')->setColumns(3)->hideOnIndex();

        // Resolved archetype preview — read-only mirror of the client's dual-report
        // classification (positive + negative) over the eight traits above. Recomputed
        // live in the browser as the traits are edited; see ArchetypeResolverService.
        yield Field::new('archetypeSummary', 'Resolved Archetypes')
            ->setFormType(ArchetypeSummaryType::class)
            ->setFormTypeOptions([
                'mapped'    => false,
                'catalogue' => $this->archetypeResolver->catalogue(),
                'resolved'  => $this->archetypeResolver->resolve($this->currentPersonality()),
            ])
            ->setColumns(12)
            ->onlyOnForms();

        // ── Panel: Physical & Contract ────────────────────────────────────────
        yield FormField::addFieldset('Physical & Contract', 'fa fa-weight-scale')->hideOnIndex();

        yield IntegerField::new('height')->setHelp('cm')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('weight')->setHelp('kg')->setColumns(3)->hideOnIndex();
        yield MoneyField::new('contractValue', 'Contract Value / wk')
            ->setCurrency('GBP')
            ->setStoredAsCents(true)
            ->setHelp('Weekly wage in pence (£1 = 100p)')
            ->setColumns(6)
            ->hideOnIndex();

        // ── Panel: Associations ───────────────────────────────────────────────
        yield FormField::addFieldset('Associations', 'fa fa-link')->hideOnIndex();

        yield AssociationField::new('agent')->setRequired(false)->setColumns(6)->hideOnIndex();
        yield AssociationField::new('guardians', 'Guardians')->onlyOnDetail();
    }

    /**
     * Personality matrix of the entity currently being edited, for the initial
     * server-side archetype render. Falls back to a fresh (all-10) profile on the
     * NEW page, where there is no persisted instance yet.
     */
    private function currentPersonality(): PersonalityProfile
    {
        $instance = $this->getContext()?->getEntity()?->getInstance();

        return $instance instanceof Player ? $instance->getPersonality() : new PersonalityProfile();
    }
}

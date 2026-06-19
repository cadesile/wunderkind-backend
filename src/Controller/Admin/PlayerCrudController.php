<?php

namespace App\Controller\Admin;

use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
use App\Enum\RecruitmentSource;
use App\Repository\ClubRepository;
use App\Repository\PlayerRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use Symfony\Component\HttpFoundation\Response;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class PlayerCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly PlayerRepository $playerRepository,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Player::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->disable(Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setDefaultSort(['lastName' => 'ASC'])
            ->overrideTemplate('crud/index', 'admin/player_index.html.twig');
    }

    public function index(AdminContext $context): KeyValueStore|Response
    {
        $responseParameters = parent::index($context);
        if ($responseParameters instanceof KeyValueStore) {
            $responseParameters->set('playerSummary', $this->playerRepository->getAdminSummary());
        }
        return $responseParameters;
    }

    /**
     * Player constructor requires several mandatory args — supply sensible
     * defaults so EasyAdmin can instantiate the form before the user fills it in.
     */
    public function createEntity(string $entityFqcn): Player
    {
        $club = $this->clubRepository->findOneBy([]);

        if ($club === null) {
            throw new \RuntimeException('No Club exists yet. Register a user first.');
        }

        return new Player(
            firstName: '',
            lastName: '',
            dateOfBirth: new \DateTimeImmutable('-16 years'),
            nationality: '',
            position: PlayerPosition::MIDFIELDER,
            recruitmentSource: RecruitmentSource::SCOUTING_NETWORK,
            potential: 50,
            currentAbility: 50,
            club: $club,
        );
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('position')->setChoices([
                'Goalkeeper' => PlayerPosition::GOALKEEPER,
                'Defender'   => PlayerPosition::DEFENDER,
                'Midfielder' => PlayerPosition::MIDFIELDER,
                'Attacker'   => PlayerPosition::ATTACKER,
            ]))
            ->add(ChoiceFilter::new('status')->setChoices([
                'Active'               => PlayerStatus::ACTIVE,
                'Loaned Out'           => PlayerStatus::LOANED_OUT,
                'Transferred'          => PlayerStatus::TRANSFERRED,
                'Transferred (Agent)'  => PlayerStatus::TRANSFERRED_VIA_AGENT,
                'Retired'              => PlayerStatus::RETIRED,
            ]))
            ->add(ChoiceFilter::new('recruitmentSource')->setChoices([
                'Scouting Network' => RecruitmentSource::SCOUTING_NETWORK,
                'Coaching Find'    => RecruitmentSource::COACHING_FIND,
                'Agent Offer'      => RecruitmentSource::AGENT_OFFER,
                'Youth Request'    => RecruitmentSource::YOUTH_REQUEST,
            ]))
            ->add(EntityFilter::new('club'))
            ->add(TextFilter::new('nationality'))
            ->add(NumericFilter::new('currentAbility'))
            ->add(NumericFilter::new('potential'));
    }

    public function configureFields(string $pageName): iterable
    {
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
            ->hideOnIndex();

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

        yield ChoiceField::new('recruitmentSource')
            ->setChoices([
                'Scouting Network' => RecruitmentSource::SCOUTING_NETWORK,
                'Coaching Find'    => RecruitmentSource::COACHING_FIND,
                'Agent Offer'      => RecruitmentSource::AGENT_OFFER,
                'Youth Request'    => RecruitmentSource::YOUTH_REQUEST,
            ])
            ->setColumns(4)
            ->hideOnIndex();

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

        yield AssociationField::new('club')->setColumns(6)->hideOnIndex();
        yield AssociationField::new('agent')->setRequired(false)->setColumns(6)->hideOnIndex();
        yield AssociationField::new('guardians', 'Guardians')->onlyOnDetail();
    }
}

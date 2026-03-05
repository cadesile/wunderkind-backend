<?php

namespace App\Controller\Admin;

use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
use App\Enum\RecruitmentSource;
use App\Repository\AcademyRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class PlayerCrudController extends AbstractCrudController
{
    public function __construct(private readonly AcademyRepository $academyRepository) {}

    public static function getEntityFqcn(): string
    {
        return Player::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['lastName' => 'ASC']);
    }

    /**
     * Player constructor requires several mandatory args — supply sensible
     * defaults so EasyAdmin can instantiate the form before the user fills it in.
     */
    public function createEntity(string $entityFqcn): Player
    {
        $academy = $this->academyRepository->findOneBy([]);

        if ($academy === null) {
            throw new \RuntimeException('No Academy exists yet. Register a user first.');
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
            academy: $academy,
        );
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('firstName');
        yield TextField::new('lastName');
        yield DateField::new('dateOfBirth')->setFormat('yyyy-MM-dd');
        yield TextField::new('nationality');

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
            ]);

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
            ]);

        yield ChoiceField::new('recruitmentSource')
            ->setChoices([
                'Scouting Network' => RecruitmentSource::SCOUTING_NETWORK,
                'Coaching Find'    => RecruitmentSource::COACHING_FIND,
                'Agent Offer'      => RecruitmentSource::AGENT_OFFER,
                'Youth Request'    => RecruitmentSource::YOUTH_REQUEST,
            ])
            ->hideOnIndex();

        yield IntegerField::new('potential')->hideOnIndex();
        yield IntegerField::new('currentAbility');
        yield IntegerField::new('contractValue')->setHelp('In pence/cents')->hideOnIndex();

        yield AssociationField::new('academy');
        yield AssociationField::new('agent')->setRequired(false)->hideOnIndex();

        yield DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex();
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\Competition\ActiveCompetition;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionResultRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only "monitor active running competitions" view. Every action is disabled
 * deliberately — instances are created by the provisioning cron and progressed by the
 * round processor, never hand-edited.
 *
 * detail() is overridden (same pattern as ClubCrudController::detail()) to render the
 * full bracket — rounds, each round's fixtures, and each fixture's result — inline on
 * one page, rather than building standalone CompetitionFixture/CompetitionResult CRUD
 * controllers (CompetitionRoundCrudController's docblock already flags that as
 * deliberately avoided, to not over-build admin surface for entities that are never
 * hand-edited).
 */
class ActiveCompetitionCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly CompetitionResultRepository $resultRepository,
    ) {}

    public static function getEntityFqcn(): string
    {
        return ActiveCompetition::class;
    }

    public function detail(AdminContext $context): Response
    {
        /** @var ActiveCompetition $competition */
        $competition = $context->getEntity()->getInstance();

        $rounds            = $this->roundRepository->findByCompetitionOrderedByIndex($competition);
        $fixturesByRoundId = [];
        $allFixtureIds     = [];

        foreach ($rounds as $round) {
            $fixtures                                        = $this->fixtureRepository->findByRoundOrderedBySlot($round);
            $fixturesByRoundId[$round->getId()->toRfc4122()] = $fixtures;
            foreach ($fixtures as $fixture) {
                $allFixtureIds[] = $fixture->getId();
            }
        }

        $resultsByFixtureId = $this->resultRepository->findByFixtureIds($allFixtureIds);

        return $this->render('admin/competition/active_competition_detail.html.twig', [
            'competition'         => $competition,
            'rounds'              => $rounds,
            'fixturesByRoundId'   => $fixturesByRoundId,
            'resultsByFixtureId'  => $resultsByFixtureId,
        ]);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('template');

        yield ChoiceField::new('status')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => ActiveCompetitionStatus::class]);

        yield IntegerField::new('entrantCapacity');
        yield ChoiceField::new('durationOption', 'Duration')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => CompetitionDuration::class]);

        yield DateTimeField::new('registrationOpenedAt');
        yield DateTimeField::new('lockedAt')->hideOnIndex();
        yield DateTimeField::new('startsAt');
        yield DateTimeField::new('endsAt');
        yield DateTimeField::new('completedAt');
        yield DateTimeField::new('cancelledAt')->hideOnIndex();
        yield TextField::new('cancellationReason')->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}

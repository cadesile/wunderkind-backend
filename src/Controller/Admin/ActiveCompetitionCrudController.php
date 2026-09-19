<?php

namespace App\Controller\Admin;

use App\Entity\Competition\ActiveCompetition;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionResultRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionSpoofEntrantService;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
 *
 * The one deliberate exception to "never hand-edited" is "Generate Spoof Entrants": an
 * admin test-data tool that clones an existing real entrant's snapshot into N new spoof
 * entrants in the same competition (see
 * CompetitionSpoofEntrantService::generateSpoofEntrantsForCompetition()). It lives here,
 * on the competition row/detail page, rather than on CompetitionEntrantCrudController —
 * spoofing is something you do TO a competition to fill it, even though the clone basis
 * happens to be one of its entrants. Only offered while REGISTERING and once at least one
 * real entrant already exists to use as a basis.
 */
class ActiveCompetitionCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly CompetitionResultRepository $resultRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
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

        $canGenerateSpoof = $competition->getStatus() === ActiveCompetitionStatus::REGISTERING
            && $this->entrantRepository->countForCompetition($competition) > 0;

        return $this->render('admin/competition/active_competition_detail.html.twig', [
            'competition'        => $competition,
            'rounds'             => $rounds,
            'fixturesByRoundId'  => $fixturesByRoundId,
            'resultsByFixtureId' => $resultsByFixtureId,
            'canGenerateSpoof'   => $canGenerateSpoof,
            'generateSpoofUrl'   => $this->generateUrl('admin', [
                'routeName'   => 'admin_active_competition_generate_spoof',
                'routeParams' => ['activeCompetition' => (string) $competition->getId()],
            ]),
        ]);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $generateSpoof = Action::new('generateSpoof', 'Generate Spoof Entrants', 'fa fa-clone')
            ->linkToUrl(fn (ActiveCompetition $competition) => $this->generateUrl('admin', [
                'routeName'   => 'admin_active_competition_generate_spoof',
                'routeParams' => ['activeCompetition' => (string) $competition->getId()],
            ]))
            ->displayIf(fn (ActiveCompetition $competition) => $competition->getStatus() === ActiveCompetitionStatus::REGISTERING
                && $this->entrantRepository->countForCompetition($competition) > 0);

        // EDIT is disabled, so EasyAdmin's default row-click fallback ([EDIT, DETAIL])
        // needs DETAIL explicitly present in the index actions to click through to it.
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $generateSpoof)
            ->add(Crud::PAGE_DETAIL, $generateSpoof);
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

    #[Route('/admin/active-competition/{activeCompetition}/generate-spoof', name: 'admin_active_competition_generate_spoof', methods: ['GET', 'POST'])]
    public function generateSpoofEntrants(ActiveCompetition $activeCompetition, Request $request, CompetitionSpoofEntrantService $service): Response
    {
        // admin_active_competition_detail is one of EasyAdmin's own pretty CRUD routes
        // (already wired to build the full crud/entity context on a direct match) — unlike
        // this action's own route, it must NOT go through the /admin?routeName=... forwarding
        // trick, which is only for routes EasyAdmin doesn't already know how to contextualise.
        $detailUrl = $this->generateUrl('admin_active_competition_detail', ['entityId' => (string) $activeCompetition->getId()]);

        if ($activeCompetition->getStatus() !== ActiveCompetitionStatus::REGISTERING) {
            $this->addFlash('danger', 'This competition is no longer accepting registrations.');
            return $this->redirect($detailUrl);
        }

        if ($this->entrantRepository->countForCompetition($activeCompetition) === 0) {
            $this->addFlash('danger', 'This competition has no registered entrants yet to use as a spoof basis.');
            return $this->redirect($detailUrl);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('generate_spoof_entrants', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Invalid CSRF token.');
                return $this->redirect($detailUrl);
            }

            $count  = max(1, (int) $request->request->get('count', 1));
            $result = $service->generateSpoofEntrantsForCompetition($activeCompetition, $count);
            $made   = count($result['created']);

            if ($made < $result['requested']) {
                $this->addFlash('warning', sprintf(
                    'Created %d of %d spoof entrants — competition filled and locked.',
                    $made,
                    $result['requested'],
                ));
            } else {
                $this->addFlash('success', sprintf('Created %d spoof entrant%s.', $made, $made === 1 ? '' : 's'));
            }

            return $this->redirect($detailUrl);
        }

        $remaining = $activeCompetition->getEntrantCapacity() - $this->entrantRepository->countForCompetition($activeCompetition);
        $actionUrl = $this->generateUrl('admin', [
            'routeName'   => 'admin_active_competition_generate_spoof',
            'routeParams' => ['activeCompetition' => (string) $activeCompetition->getId()],
        ]);

        return $this->render('admin/competition/generate_spoof_entrants.html.twig', [
            'competition' => $activeCompetition,
            'remaining'   => max(0, $remaining),
            'actionUrl'   => $actionUrl,
            'detailUrl'   => $detailUrl,
        ]);
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionFixture;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionResultRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Service\Competition\CompetitionRoundProcessorService;
use App\Service\Competition\CompetitionSpoofEntrantService;
use Doctrine\ORM\EntityManagerInterface;
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
 * Read-only "monitor active running competitions" view — NEW/EDIT stay disabled
 * (instances are created by the provisioning cron and progressed by the round
 * processor, never hand-edited), but DELETE/batch-delete are deliberately left enabled
 * (see below) as an admin test-data cleanup tool.
 *
 * detail() is overridden (same pattern as ClubCrudController::detail()) to render the
 * full bracket — rounds, each round's fixtures, and each fixture's result — inline on
 * one page, rather than building standalone CompetitionFixture/CompetitionResult CRUD
 * controllers (CompetitionRoundCrudController's docblock already flags that as
 * deliberately avoided, to not over-build admin surface for entities that are never
 * hand-edited).
 *
 * Deleting an ActiveCompetition (single row, batch-selected, or "Clear All") relies
 * entirely on the real DB-level ON DELETE CASCADE chain set up in the Phase 1 migration:
 * active_competition -> competition_round -> competition_fixture -> competition_result,
 * and active_competition -> competition_entrant -> entrant_reward_claim. No manual FK
 * cleanup is needed (contrast ClubCrudController::deleteEntity(), which needs one because
 * some of Club's relations aren't cascade-configured). The one thing this does NOT clean
 * up: spoof Club/User rows created for this competition's entrants —
 * competition_entrant.club_id -> club.id CASCADEs the other way (deleting a Club deletes
 * its entrants, not the reverse), so those are orphaned here by design and would need
 * separate cleanup if that matters.
 *
 * Four deliberate exceptions to "never hand-edited", all admin test tools:
 *
 * - "Generate Spoof Entrants" clones an existing real entrant's snapshot into N new spoof
 *   entrants in the same competition (see
 *   CompetitionSpoofEntrantService::generateSpoofEntrantsForCompetition()). Only offered
 *   while REGISTERING and once at least one real entrant already exists to use as a basis.
 * - "Spoof All Entrants" is the zero-setup version: fills the competition to capacity in
 *   one click, bootstrapping a fully-synthetic entrant first if none exist yet (see
 *   CompetitionSpoofEntrantService::spoofAllEntrants()) — for standing up an entire test
 *   competition (registration through to result generation) without any real club ever
 *   registering.
 * - "Generate Result" (per fixture row on the detail page) forces a single PENDING
 *   fixture to resolve immediately, bypassing its round's scheduledAt wait — for
 *   exercising round-by-round processing locally without waiting out real durations. See
 *   CompetitionRoundProcessorService::forceResolveFixture().
 * - "Clear All" deletes every ActiveCompetition row in one confirmed action — the bulk
 *   equivalent of selecting every row and using batch-delete, for wiping all local test
 *   competitions in one go.
 */
class ActiveCompetitionCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly CompetitionResultRepository $resultRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly EntityManagerInterface $em,
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
        $canSpoofAll = $competition->getStatus() === ActiveCompetitionStatus::REGISTERING
            && $this->entrantRepository->countForCompetition($competition) < $competition->getEntrantCapacity();

        $generateResultUrlByFixtureId = [];
        foreach ($allFixtureIds as $fixtureId) {
            $generateResultUrlByFixtureId[$fixtureId->toRfc4122()] = $this->generateUrl('admin_active_competition_fixture_generate_result', ['fixture' => (string) $fixtureId]);
        }

        return $this->render('admin/competition/active_competition_detail.html.twig', [
            'competition'                   => $competition,
            'rounds'                        => $rounds,
            'fixturesByRoundId'             => $fixturesByRoundId,
            'resultsByFixtureId'            => $resultsByFixtureId,
            'canGenerateSpoof'              => $canGenerateSpoof,
            'generateSpoofUrl'              => $this->generateUrl('admin', [
                'routeName'   => 'admin_active_competition_generate_spoof',
                'routeParams' => ['activeCompetition' => (string) $competition->getId()],
            ]),
            'canSpoofAll'                   => $canSpoofAll,
            'spoofAllUrl'                   => $this->generateUrl('admin', [
                'routeName'   => 'admin_active_competition_spoof_all',
                'routeParams' => ['activeCompetition' => (string) $competition->getId()],
            ]),
            'generateResultUrlByFixtureId' => $generateResultUrlByFixtureId,
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

        $spoofAll = Action::new('spoofAll', 'Spoof All Entrants', 'fa fa-users')
            ->linkToUrl(fn (ActiveCompetition $competition) => $this->generateUrl('admin', [
                'routeName'   => 'admin_active_competition_spoof_all',
                'routeParams' => ['activeCompetition' => (string) $competition->getId()],
            ]))
            ->displayIf(fn (ActiveCompetition $competition) => $competition->getStatus() === ActiveCompetitionStatus::REGISTERING
                && $this->entrantRepository->countForCompetition($competition) < $competition->getEntrantCapacity());

        $clearAll = Action::new('clearAll', 'Clear All', 'fa fa-trash')
            ->createAsGlobalAction()
            ->linkToUrl(fn () => $this->generateUrl('admin', ['routeName' => 'admin_active_competitions_clear_all']))
            ->setCssClass('btn btn-danger');

        // EDIT/NEW stay disabled — DELETE (and the batch-delete it implies) is left
        // enabled deliberately, see class docblock. EDIT being disabled means EasyAdmin's
        // default row-click fallback ([EDIT, DETAIL]) needs DETAIL explicitly present in
        // the index actions to click through to it.
        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $generateSpoof)
            ->add(Crud::PAGE_DETAIL, $generateSpoof)
            ->add(Crud::PAGE_INDEX, $spoofAll)
            ->add(Crud::PAGE_DETAIL, $spoofAll)
            ->add(Crud::PAGE_INDEX, $clearAll);
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

    #[Route('/admin/active-competition/{activeCompetition}/spoof-all', name: 'admin_active_competition_spoof_all', methods: ['GET', 'POST'])]
    public function spoofAllEntrants(ActiveCompetition $activeCompetition, Request $request, CompetitionSpoofEntrantService $service): Response
    {
        $detailUrl = $this->generateUrl('admin_active_competition_detail', ['entityId' => (string) $activeCompetition->getId()]);

        if ($activeCompetition->getStatus() !== ActiveCompetitionStatus::REGISTERING) {
            $this->addFlash('danger', 'This competition is no longer accepting registrations.');
            return $this->redirect($detailUrl);
        }

        $remaining = $activeCompetition->getEntrantCapacity() - $this->entrantRepository->countForCompetition($activeCompetition);
        if ($remaining <= 0) {
            $this->addFlash('danger', 'This competition is already full.');
            return $this->redirect($detailUrl);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('spoof_all_entrants', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Invalid CSRF token.');
                return $this->redirect($detailUrl);
            }

            $result = $service->spoofAllEntrants($activeCompetition);
            $made   = count($result['created']);

            if ($made < $result['requested']) {
                $this->addFlash('warning', sprintf('Created %d of %d spoof entrants.', $made, $result['requested']));
            } else {
                $this->addFlash('success', sprintf('Spoofed %d entrant%s — competition filled and locked.', $made, $made === 1 ? '' : 's'));
            }

            return $this->redirect($detailUrl);
        }

        $actionUrl = $this->generateUrl('admin', [
            'routeName'   => 'admin_active_competition_spoof_all',
            'routeParams' => ['activeCompetition' => (string) $activeCompetition->getId()],
        ]);

        return $this->render('admin/competition/spoof_all_entrants.html.twig', [
            'competition' => $activeCompetition,
            'remaining'   => $remaining,
            'actionUrl'   => $actionUrl,
            'detailUrl'   => $detailUrl,
        ]);
    }

    /**
     * POST-only, no confirmation page — this never renders an EasyAdmin-layout template
     * (only flashes + redirects), so unlike the GET/POST actions above it doesn't need the
     * /admin?routeName=... forwarding trick; a plain direct route works fine here.
     */
    #[Route('/admin/active-competition/fixture/{fixture}/generate-result', name: 'admin_active_competition_fixture_generate_result', methods: ['POST'])]
    public function generateFixtureResult(CompetitionFixture $fixture, Request $request, CompetitionRoundProcessorService $processor): Response
    {
        $activeCompetition = $fixture->getRound()->getActiveCompetition();
        $detailUrl         = $this->generateUrl('admin_active_competition_detail', ['entityId' => (string) $activeCompetition->getId()]);

        if (!$this->isCsrfTokenValid('generate_fixture_result', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($detailUrl);
        }

        try {
            $result = $processor->forceResolveFixture($fixture);
            $this->addFlash('success', sprintf('Generated result: %d – %d.', $result->getHomeScore(), $result->getAwayScore()));
        } catch (\RuntimeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($detailUrl);
    }

    /**
     * Deletes every ActiveCompetition row — the bulk equivalent of selecting every row on
     * the index and using batch-delete. Relies entirely on the DB-level ON DELETE CASCADE
     * chain (see class docblock); a DQL bulk DELETE still fires those constraints exactly
     * like any other DELETE, so no manual round/fixture/result/entrant cleanup is needed
     * here. Named "active-competitions" (plural) — a one-segment path under the singular
     * CRUD prefix would collide with EasyAdmin's own pretty-URL detail route.
     */
    #[Route('/admin/active-competitions/clear-all', name: 'admin_active_competitions_clear_all', methods: ['GET', 'POST'])]
    public function clearAll(Request $request): Response
    {
        $indexUrl = $this->generateUrl('admin_active_competition_index');
        $count    = (int) $this->em->createQuery('SELECT COUNT(a.id) FROM App\Entity\Competition\ActiveCompetition a')->getSingleScalarResult();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('clear_all_active_competitions', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Invalid CSRF token.');
                return $this->redirect($indexUrl);
            }

            $deleted = $this->em->createQuery('DELETE FROM App\Entity\Competition\ActiveCompetition a')->execute();
            $this->addFlash('success', sprintf('Cleared %d competition%s (rounds, fixtures, results, and entrants included).', $deleted, $deleted === 1 ? '' : 's'));

            return $this->redirect($indexUrl);
        }

        $actionUrl = $this->generateUrl('admin', ['routeName' => 'admin_active_competitions_clear_all']);

        return $this->render('admin/competition/clear_all_active_competitions.html.twig', [
            'count'     => $count,
            'actionUrl' => $actionUrl,
            'indexUrl'  => $indexUrl,
        ]);
    }
}

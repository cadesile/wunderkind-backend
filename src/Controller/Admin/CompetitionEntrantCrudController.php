<?php

namespace App\Controller\Admin;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Exception\SpoofSnapshotValidationException;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionEntrantSquadRepository;
use App\Repository\Competition\CompetitionEntrantSubmissionRepository;
use App\Service\Competition\CompetitionRegistrationService;
use App\Service\Competition\CompetitionSpoofEntrantService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only view of every club snapshot submitted at registration/resubmission — the
 * primary use is debugging/support: confirming what a club actually sent (roster size,
 * tactics, attribute values) without needing direct DB access. Entrants are created by
 * CompetitionRegistrationService and updated by round processing/resubmission, never
 * hand-edited, so every action is disabled — same reasoning as
 * ActiveCompetitionCrudController/CompetitionRoundCrudController.
 *
 * Three deliberate exceptions to "never hand-edited", all admin test-data tools:
 *
 * - "Generate Spoof Entrants" lives on ActiveCompetitionCrudController (the competition
 *   row/detail page), not here — spoofing fills out a competition, so the trigger belongs
 *   at the competition level even though it clones an entrant's snapshot under the hood.
 * - "Create Spoof" lives HERE: it takes a hand-pasted club/players/staff/facilities
 *   snapshot (the same shape a real client POSTs to /api/competitions/{id}/register) and
 *   registers it into a chosen open competition, for precisely reproducing a specific test
 *   scenario rather than cloning/randomly varying an existing one. See
 *   CompetitionSpoofEntrantService::createSpoofEntrantFromSnapshot().
 * - "Generate Spoof" lives on an entrant's own DETAIL page: the same call as "Create Spoof"
 *   with Randomise ticked, but sourced from the entrant being viewed and registered into its
 *   own competition, so there is nothing to paste or choose. Only offered while that
 *   competition is still REGISTERING, since the service refuses otherwise.
 *
 * `club.isSpoof` is surfaced on this index/detail so any spoof-generated entrant (from
 * either tool) stays identifiable from this debugging view.
 */
class CompetitionEntrantCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ActiveCompetitionRepository $activeCompetitionRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionEntrantSquadRepository $squadRepository,
        private readonly CompetitionEntrantSubmissionRepository $submissionRepository,
    ) {}

    public static function getEntityFqcn(): string
    {
        return CompetitionEntrant::class;
    }

    /**
     * The page size is no longer load-bearing, but it stays small on purpose.
     *
     * This screen used to time out (504) in production because every row carried the whole
     * registration snapshot — squad, staff, tactics, every player's attributes and
     * appearance — and ORM 3 removed PARTIAL hydration, so listing N entrants decoded N
     * whole snapshots whether or not a column rendered them. Halving the page was the only
     * lever available then, and this docblock used to prescribe the real cure: move the
     * snapshot into its own table.
     *
     * That has now been done. The squad lives in competition_entrant_squad and this entity
     * holds only a bounded club display block, so an index page no longer decodes a squad at
     * all — the summary columns below read the small block or a denormalised counter. The
     * page size is now just a sensible default for a read-only audit list.
     */
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['registeredAt' => 'DESC'])
            ->setPaginatorPageSize(10)
            ->setHelp('index', 'Squad data lives in a separate row and is not loaded here — open an entrant to see its full submitted snapshot.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $createSpoof = Action::new('createSpoof', 'Create Spoof', 'fa fa-plus')
            ->createAsGlobalAction()
            ->linkToUrl(fn () => $this->generateUrl('admin', ['routeName' => 'admin_competition_entrant_create_spoof']));

        // Clones THIS entrant's snapshot, randomised — the "Create Spoof" screen's process
        // with Randomise ticked, minus the paste. Shown only while the entrant's own
        // competition is still REGISTERING, because the service refuses otherwise: an action
        // that can only ever flash an error is worse than no action.
        $generateSpoof = Action::new('generateSpoofFromEntrant', 'Generate Spoof', 'fa fa-clone')
            ->linkToUrl(fn (CompetitionEntrant $entrant) => $this->generateUrl('admin', [
                'routeName'   => 'admin_competition_entrant_generate_spoof',
                'routeParams' => ['entrant' => (string) $entrant->getId()],
            ]))
            ->displayIf(static fn (CompetitionEntrant $entrant): bool => $entrant->getActiveCompetition()->getStatus() === ActiveCompetitionStatus::REGISTERING);

        // EDIT is disabled, so EasyAdmin's default row-click fallback ([EDIT, DETAIL])
        // needs DETAIL explicitly present in the index actions to click through to it.
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $createSpoof)
            ->add(Crud::PAGE_DETAIL, $generateSpoof);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('activeCompetition', 'Competition');
        yield AssociationField::new('club');
        yield BooleanField::new('club.isSpoof', 'Spoof')->renderAsSwitch(false);
        yield IntegerField::new('seed');

        yield ChoiceField::new('status')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => CompetitionEntrantStatus::class]);

        // Summary columns read the display block or the denormalised counter, never a squad row.
        yield IntegerField::new('snapshotPlayerCount', 'Players');
        yield TextField::new('snapshotFormation', 'Formation')->hideOnIndex();
        yield TextField::new('snapshotPlayingStyle', 'Playing Style');

        yield IntegerField::new('snapshotVersion', 'Version')->hideOnIndex();
        yield DateTimeField::new('snapshotLockedAt')->hideOnIndex();
        yield DateTimeField::new('registeredAt');
        yield AssociationField::new('eliminatedInRound')->hideOnIndex();

        yield CodeEditorField::new('clubDisplayJsonPretty', 'Club Display Block')
            ->setLanguage('js')
            ->setNumOfRows(24)
            ->onlyOnDetail();

        // The submitted XI and staff, from competition_entrant_squad.
        //
        // onlyOnDetail() is doing real work here, not tidying a layout: this is the one field
        // on this screen that loads a second row, and letting it onto the index would restore
        // precisely the per-row squad decode that made this page time out before the split.
        //
        // setValue('') is the documented EasyAdmin workaround for a virtual field — without a
        // non-null placeholder, CommonPreConfigurator tries PropertyAccessor::getValue() for
        // the nonexistent `submittedSquad` getter, renders its "inaccessible" template, and
        // ignores formatValue() entirely. See CLAUDE.md's Key Gotchas.
        yield CodeEditorField::new('submittedSquad', 'Submitted Squad')
            ->setLanguage('js')
            ->setNumOfRows(28)
            ->onlyOnDetail()
            ->setValue('')
            ->formatValue(fn ($value, ?CompetitionEntrant $entrant): string => $entrant === null
                ? '{}'
                : ($this->squadRepository->findForEntrant($entrant)?->getSquadJsonPretty() ?? '{}'));

        // The posted body as the client sent it, which the two fields above cannot show
        // between them: one is a picked ~22-key display block and the other is the engine's
        // inputs, so every other club key the client sends is absent from both. Same
        // dummy-setValue() placeholder as above, for the same CommonPreConfigurator reason.
        //
        // Loaded only here, on one entrant's detail page. Never add this to the index — these
        // are the largest rows in the domain and the index renders every entrant.
        yield CodeEditorField::new('submittedEnvelope', 'Submitted Envelope (verbatim)')
            ->setLanguage('js')
            ->setNumOfRows(28)
            ->onlyOnDetail()
            ->setValue('')
            ->setHelp('Exactly what the client posted, stored unmodified and never pruned. Nothing reads it at runtime; it is kept for forensics and for tournament features still in development.')
            ->formatValue(fn ($value, ?CompetitionEntrant $entrant): string => $entrant === null
                ? '{}'
                : ($this->submissionRepository->findForEntrant($entrant)?->getEnvelopeJsonPretty()
                    ?? '// No submission row. Registered before this was stored, or built by the spoof path.'));
    }

    /**
     * Registers a new spoof entrant built from THIS entrant's snapshot, randomised.
     *
     * Identical to what admin_competition_entrant_create_spoof does with "Randomise" ticked —
     * same service call, same validation — except the snapshot comes from the entrant being
     * viewed instead of being pasted in, and the competition is its own rather than chosen.
     *
     * GET renders a confirmation; POST does the work behind a CSRF token. A bare GET link
     * must not create data: EasyAdmin actions are links, and browsers (and link prefetchers)
     * follow those freely. Same split as admin_active_competition_generate_spoof.
     *
     * Two-segment path under the entrant id, so unlike the create-spoof route above it does
     * not collide with EasyAdmin's /admin/competition-entrant/{entityId} detail route.
     */
    #[Route('/admin/competition-entrant/{entrant}/generate-spoof', name: 'admin_competition_entrant_generate_spoof', methods: ['GET', 'POST'])]
    public function generateSpoofFromEntrant(CompetitionEntrant $entrant, Request $request, CompetitionSpoofEntrantService $service, CompetitionEntrantSquadRepository $squadRepository): Response
    {
        // An EasyAdmin pretty CRUD route — it builds its own crud/entity context on a direct
        // match, so it must NOT be wrapped in the /admin?routeName=... forwarding used for
        // this action's own route. See ActiveCompetitionCrudController for the same note.
        $detailUrl   = $this->generateUrl('admin_competition_entrant_detail', ['entityId' => (string) $entrant->getId()]);
        $competition = $entrant->getActiveCompetition();

        if ($competition->getStatus() !== ActiveCompetitionStatus::REGISTERING) {
            $this->addFlash('danger', 'This competition is no longer accepting registrations.');

            return $this->redirect($detailUrl);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('generate_spoof_from_entrant', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Invalid CSRF token.');

                return $this->redirect($detailUrl);
            }

            try {
                $spoof = $service->createSpoofEntrantFromSnapshot(
                    $competition,
                    CompetitionRegistrationService::snapshotEnvelopeFrom($entrant, $squadRepository->findForEntrant($entrant)),
                    randomise: true,
                );
            } catch (SpoofSnapshotValidationException $e) {
                // A snapshot already accepted at registration can still fail here if the
                // validator has tightened since — report it rather than swallowing it.
                $this->addFlash('danger', 'That snapshot no longer validates: ' . implode(' ', $e->getViolations()));

                return $this->redirect($detailUrl);
            } catch (\RuntimeException $e) {
                $this->addFlash('danger', $e->getMessage());

                return $this->redirect($detailUrl);
            }

            $this->addFlash('success', sprintf('Created spoof entrant "%s".', $spoof->getClub()->getName()));

            return $this->redirect($this->generateUrl('admin_competition_entrant_detail', ['entityId' => (string) $spoof->getId()]));
        }

        return $this->render('admin/competition/generate_spoof_from_entrant.html.twig', [
            'competition'  => $competition,
            'clubName'     => $entrant->getClub()->getName(),
            'playerCount'  => $entrant->getSnapshotPlayerCount(),
            'remaining'    => $competition->getEntrantCapacity() - $this->entrantRepository->countForCompetition($competition),
            'detailUrl'    => $detailUrl,
            'actionUrl'    => $this->generateUrl('admin', [
                'routeName'   => 'admin_competition_entrant_generate_spoof',
                'routeParams' => ['entrant' => (string) $entrant->getId()],
            ]),
        ]);
    }

    // A single extra path segment here (e.g. /admin/competition-entrant/create-spoof) would
    // collide with EasyAdmin's own pretty-URL detail route (/admin/competition-entrant/{entityId})
    // and get shadowed by it — Symfony matches that wildcard route first. This global action has
    // no entity to scope a two-segment path under (contrast admin_active_competition_generate_spoof,
    // which nests under a real {activeCompetition} id), so it lives under a distinct path segment
    // instead.
    #[Route('/admin/competition-entrants/create-spoof', name: 'admin_competition_entrant_create_spoof', methods: ['GET', 'POST'])]
    public function createSpoof(Request $request, CompetitionSpoofEntrantService $service): Response
    {
        $openCompetitions = $this->activeCompetitionRepository->findByStatus(ActiveCompetitionStatus::REGISTERING);
        $indexUrl         = $this->generateUrl('admin_competition_entrant_index');

        $formState = [
            'activeCompetitionId' => $request->request->get('activeCompetitionId', ''),
            'snapshot'            => (string) $request->request->get('snapshot', ''),
            'randomise'           => (bool) $request->request->get('randomise', false),
            'errors'              => [],
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('create_spoof_entrant', $request->request->get('_token'))) {
                $formState['errors'][] = 'Invalid CSRF token.';
            } elseif ($formState['activeCompetitionId'] === '') {
                $formState['errors'][] = 'Choose a competition to register this spoof club into.';
            } else {
                try {
                    $activeCompetition = $this->activeCompetitionRepository->find($formState['activeCompetitionId']);
                } catch (\Throwable) {
                    $activeCompetition = null;
                }
                if ($activeCompetition === null) {
                    $formState['errors'][] = 'That competition no longer exists.';
                } else {
                    $decoded = json_decode($formState['snapshot'], true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                        $formState['errors'][] = 'That is not valid JSON: ' . json_last_error_msg();
                    } else {
                        try {
                            $entrant = $service->createSpoofEntrantFromSnapshot($activeCompetition, $decoded, $formState['randomise']);

                            $this->addFlash('success', sprintf('Created spoof entrant for "%s".', $entrant->getClub()->getName()));

                            return $this->redirect($this->generateUrl('admin_competition_entrant_detail', ['entityId' => (string) $entrant->getId()]));
                        } catch (SpoofSnapshotValidationException $e) {
                            $formState['errors'] = $e->getViolations();
                        } catch (\RuntimeException $e) {
                            $formState['errors'][] = $e->getMessage();
                        }
                    }
                }
            }
        }

        return $this->render('admin/competition/create_spoof_entrant.html.twig', [
            'openCompetitions' => $openCompetitions,
            'formState'        => $formState,
            'indexUrl'         => $indexUrl,
        ]);
    }
}
